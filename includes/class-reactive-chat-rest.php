<?php
/**
 * The customer data endpoint: POST /wp-json/reactive-chat/v1/kundendaten.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * reactive.chat fetches the orders of ONE confirmed customer here, per
 * question:
 *
 *   POST .../reactive-chat/v1/kundendaten
 *   X-RC-Zeit:     1791196400
 *   X-RC-Signatur: hex(HMAC_SHA256(ENDPOINT_SECRET, time . '.' . rawBody))
 *   { "user_id": "4711", "anliegen": "bestellung", "referenz": "2026-0815",
 *     "sprache": "de", "nonce": "<32 hex>" }
 *
 * Answer 200: { "bestellungen": [ ... ] } in the fixed schema.
 *
 * ONLY THE SIGNED BODY COUNTS. Only $request->get_body() is read, never
 * get_param(): WP_REST_Request merges query parameters (?user_id=...) into the
 * parameters, and those are not signed. The logged-in session (cookie) plays
 * no role either - the endpoint never asks get_current_user_id().
 *
 * reactive.chat only sends user IDs it received in a valid, unexpired userHash
 * signed by this shop (identify()). The endpoint secret proves the caller is
 * reactive.chat; the userHash proves the visitor is that customer.
 */
final class Reactive_Chat_Rest {

	const NAMESPACE_V1 = 'reactive-chat/v1';
	const ROUTE        = '/kundendaten';

	/** Largest accepted body (bytes); reactive.chat sends about 200. */
	const MAX_BODY = 4096;

	/** The user ID of the test button in the reactive.chat account. */
	const TEST_USER = 'rc-test';

	/** Set hooks. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/** Registers the route. */
	public static function register() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * The full address of the endpoint (for the settings page).
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Mode, body size, signature, time window, nonce - in this order. The
	 * nonce is only consumed AFTER a valid signature: otherwise anybody without
	 * the secret could block foreign nonces in advance.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		if ( ! Reactive_Chat_Options::pull_enabled() ) {
			return new WP_Error( 'rc_disabled', __( 'The customer data endpoint is switched off.', 'reactive-chat' ), array( 'status' => 404 ) );
		}
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'rc_too_large', __( 'Request too large.', 'reactive-chat' ), array( 'status' => 413 ) );
		}
		$error = Reactive_Chat_Signature::check(
			Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_ENDPOINT ),
			$request->get_header( 'x_rc_zeit' ),
			$request->get_header( 'x_rc_signatur' ),
			$body,
			time()
		);
		if ( '' !== $error ) {
			// One reason for all cases: without the secret nobody learns whether
			// it was the time, the format or the value.
			return new WP_Error( 'rc_signature', __( 'Invalid or expired signature.', 'reactive-chat' ), array( 'status' => 401 ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'rc_bad_json', __( 'The request body is not valid JSON.', 'reactive-chat' ), array( 'status' => 400 ) );
		}
		if ( ! Reactive_Chat_Nonce::claim( isset( $data['nonce'] ) ? $data['nonce'] : null ) ) {
			return new WP_Error( 'rc_replay', __( 'This request was already processed.', 'reactive-chat' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Builds the answer.
	 *
	 * @param WP_REST_Request $request Request (already checked).
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$data     = json_decode( (string) $request->get_body(), true );
		$response = new WP_REST_Response( self::answer( is_array( $data ) ? $data : array(), Reactive_Chat_Options::fields() ), 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * The core of the answer, without HTTP.
	 *
	 * @param array    $data   The signed body.
	 * @param string[] $fields Effective paths.
	 * @return array { bestellungen: array[] }
	 */
	public static function answer( array $data, array $fields ) {
		$empty   = array( 'bestellungen' => array() );
		$user_id = isset( $data['user_id'] ) && ( is_string( $data['user_id'] ) || is_int( $data['user_id'] ) ) ? (string) $data['user_id'] : '';
		$concern = isset( $data['anliegen'] ) && is_string( $data['anliegen'] ) ? $data['anliegen'] : '';

		// The test button in the account (and the self-test of this plugin):
		// signature and reachability only, no data.
		if ( 'test' === $concern || self::TEST_USER === $user_id ) {
			return $empty;
		}
		// Only a real WordPress user ID. "0" would be all guest orders.
		if ( ! preg_match( '/^[1-9][0-9]{0,19}$/', $user_id ) ) {
			return $empty;
		}
		$reference = Reactive_Chat_Schema::normalize_reference( isset( $data['referenz'] ) ? $data['referenz'] : '' );

		$switched = false;
		$locale   = self::locale_for( isset( $data['sprache'] ) ? $data['sprache'] : '' );
		if ( '' !== $locale && function_exists( 'switch_to_locale' ) ) {
			// Status names ("Completed", "Abgeschlossen", ...) in the visitor's
			// language, if the language pack is installed.
			$switched = switch_to_locale( $locale );
		}
		try {
			$orders = Reactive_Chat_Orders::for_customer( (int) $user_id, $reference, $fields );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
		return array( 'bestellungen' => $orders );
	}

	/**
	 * The installed WordPress locale for the visitor's language code, or ''.
	 *
	 * @param mixed $language Language code from reactive.chat ("de", "pl", "es-mx", ...).
	 * @return string
	 */
	public static function locale_for( $language ) {
		if ( ! is_string( $language ) || ! preg_match( '/^([a-z]{2,3})(?:-([a-z]{2}))?$/', $language, $m ) ) {
			return '';
		}
		if ( 'en' === $m[1] && empty( $m[2] ) ) {
			return 'en_US';
		}
		$installed = function_exists( 'get_available_languages' ) ? get_available_languages() : array();
		if ( ! is_array( $installed ) ) {
			return '';
		}
		if ( ! empty( $m[2] ) ) {
			$exact = $m[1] . '_' . strtoupper( $m[2] );
			if ( in_array( $exact, $installed, true ) ) {
				return $exact;
			}
		}
		foreach ( $installed as $locale ) {
			if ( is_string( $locale ) && ( $locale === $m[1] || 0 === strpos( $locale, $m[1] . '_' ) ) ) {
				return $locale;
			}
		}
		return '';
	}
}

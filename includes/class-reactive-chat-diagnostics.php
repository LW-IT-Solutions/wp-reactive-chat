<?php
/**
 * Status checks, endpoint self-test and API key check.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the status box of the settings page shows.
 *
 * Two checks talk to a server, and only when the shop manager clicks:
 *
 *   Test endpoint  A signed test request from this site to its own customer
 *                  data endpoint - the same request reactive.chat sends with
 *                  its test button (user "rc-test", no customer data). It
 *                  shows whether the address works without a redirect and
 *                  whether security plugins or the host strip the headers.
 *   Check API key  GET {base}/v1/me with the API key: valid, which workspace,
 *                  does it carry the scope kundendaten:schreiben? Sends no
 *                  customer data.
 *
 * Results are kept in one option (autoload = no) without secrets.
 */
final class Reactive_Chat_Diagnostics {

	const OPTION          = 'reactive_chat_diagnostics';
	const ACTION_ENDPOINT = 'reactive_chat_test_endpoint';
	const ACTION_KEY      = 'reactive_chat_check_key';
	const IMPORT_SCOPE    = 'kundendaten:schreiben';

	/** Set hooks. */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_ENDPOINT, array( __CLASS__, 'handle_endpoint_test' ) );
		add_action( 'admin_post_' . self::ACTION_KEY, array( __CLASS__, 'handle_key_check' ) );
	}

	/**
	 * A stored result.
	 *
	 * @param string $name 'endpoint' or 'api_key'.
	 * @return array|null
	 */
	public static function result( $name ) {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $name ] ) && is_array( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Stores a result.
	 *
	 * @param string $name   'endpoint' or 'api_key'.
	 * @param array  $result Result without secrets.
	 */
	private static function store( $name, array $result ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $name ] = $result + array( 'time' => time() );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $all, '', 'no' );
		} else {
			update_option( self::OPTION, $all, false );
		}
	}

	/** Forgets the stored results (after settings that affect them changed). */
	public static function forget() {
		delete_option( self::OPTION );
	}

	// ---------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------

	/** Button "Test endpoint". */
	public static function handle_endpoint_test() {
		if ( ! current_user_can( Reactive_Chat_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'reactive-chat' ), 403 );
		}
		check_admin_referer( self::ACTION_ENDPOINT );
		$result = self::test_endpoint();
		self::store( 'endpoint', $result );
		Reactive_Chat_Admin::add_notice( $result['ok'] ? 'success' : 'error', $result['message'] );
		Reactive_Chat_Admin::redirect_back();
	}

	/** Button "Check API key". */
	public static function handle_key_check() {
		if ( ! current_user_can( Reactive_Chat_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'reactive-chat' ), 403 );
		}
		check_admin_referer( self::ACTION_KEY );
		$result = self::check_key();
		self::store( 'api_key', $result );
		Reactive_Chat_Admin::add_notice( $result['ok'] ? 'success' : 'error', $result['message'] );
		Reactive_Chat_Admin::redirect_back();
	}

	// ---------------------------------------------------------------------
	// Checks
	// ---------------------------------------------------------------------

	/**
	 * Sends a signed test request to the own customer data endpoint.
	 *
	 * @return array { ok: bool, code: int, message: string }
	 */
	public static function test_endpoint() {
		if ( ! Reactive_Chat_Options::pull_enabled() ) {
			return self::outcome( false, 0, __( 'The customer data endpoint is switched off. Choose the mode "On request" or "Both" first.', 'reactive-chat' ) );
		}
		$secret = Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_ENDPOINT );
		if ( '' === $secret ) {
			return self::outcome( false, 0, __( 'No endpoint secret is set. Paste the secret from your reactive.chat project first.', 'reactive-chat' ) );
		}
		$body = (string) wp_json_encode(
			array(
				'user_id'  => Reactive_Chat_Rest::TEST_USER,
				'anliegen' => 'test',
				'referenz' => null,
				'sprache'  => 'en',
				'nonce'    => bin2hex( random_bytes( 16 ) ),
			)
		);
		$time     = (string) time();
		$response = wp_remote_post(
			Reactive_Chat_Rest::url(),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				// Verified like reactive.chat does: an invalid certificate fails there too.
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'X-RC-Zeit'     => $time,
					'X-RC-Signatur' => Reactive_Chat_Signature::sign( $secret, $time, $body ),
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			if ( false !== stripos( $response->get_error_message(), 'ssl' ) || false !== stripos( $response->get_error_message(), 'certificate' ) ) {
				return self::outcome( false, 0, __( 'The TLS certificate of this site was not accepted. reactive.chat needs a valid certificate to call the endpoint.', 'reactive-chat' ) );
			}
			return self::outcome( false, 0, __( 'This site could not reach its own endpoint (loopback request failed). Some hosts block such requests; reactive.chat may still reach it. Use the test button in your reactive.chat project to be sure.', 'reactive-chat' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $data ) && isset( $data['bestellungen'] ) && is_array( $data['bestellungen'] ) ) {
			if ( 0 !== strpos( Reactive_Chat_Rest::url(), 'https://' ) ) {
				return self::outcome( false, $code, __( 'The endpoint answers correctly, but its address is not https. reactive.chat only calls https addresses on port 443.', 'reactive-chat' ) );
			}
			return self::outcome( true, $code, __( 'The customer data endpoint answers correctly. Now press the test button in your reactive.chat project as well.', 'reactive-chat' ) );
		}
		if ( $code >= 300 && $code < 400 ) {
			return self::outcome( false, $code, __( 'The endpoint address redirects. reactive.chat does not follow redirects - check the site address (https, www) in Settings > General.', 'reactive-chat' ) );
		}
		if ( 401 === $code ) {
			return self::outcome( false, $code, __( 'The endpoint rejected the signed test request. A proxy, firewall or security plugin probably removes the X-RC-Zeit and X-RC-Signatur headers.', 'reactive-chat' ) );
		}
		if ( 404 === $code ) {
			return self::outcome( false, $code, __( 'The endpoint was not found. The REST API may be disabled by a security plugin.', 'reactive-chat' ) );
		}
		/* translators: %d: HTTP status code */
		return self::outcome( false, $code, sprintf( __( 'The endpoint answered with HTTP %d instead of the expected answer.', 'reactive-chat' ), $code ) );
	}

	/**
	 * Checks the API key against GET {base}/v1/me.
	 *
	 * @return array { ok: bool, code: int, message: string, workspace?: string, key_name?: string }
	 */
	public static function check_key() {
		$key  = Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_API_KEY );
		$base = rtrim( (string) Reactive_Chat_Options::get( 'base_url' ), '/' );
		if ( '' === $key || '' === $base ) {
			return self::outcome( false, 0, __( 'No API key is set.', 'reactive-chat' ) );
		}
		$response = wp_remote_get(
			$base . '/v1/me',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => Reactive_Chat_Import::api_headers( $key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::outcome( false, 0, __( 'reactive.chat could not be reached. Check the reactive.chat address under Advanced and whether your host allows outgoing requests.', 'reactive-chat' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $code || 403 === $code ) {
			return self::outcome( false, $code, __( 'reactive.chat does not accept this API key. Create a new key in your account and paste it again.', 'reactive-chat' ) );
		}
		$data = json_decode( $body, true );
		if ( 200 !== $code || ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			/* translators: %d: HTTP status code */
			return self::outcome( false, $code, sprintf( __( 'Unexpected answer from reactive.chat (HTTP %d).', 'reactive-chat' ), $code ) );
		}
		$me        = $data['data'];
		$scopes    = isset( $me['key']['scopes'] ) && is_array( $me['key']['scopes'] ) ? $me['key']['scopes'] : array();
		$workspace = isset( $me['workspace']['name'] ) && is_string( $me['workspace']['name'] ) ? Reactive_Chat_Schema::text( $me['workspace']['name'], 80 ) : '';
		$key_name  = isset( $me['key']['name'] ) && is_string( $me['key']['name'] ) ? Reactive_Chat_Schema::text( $me['key']['name'], 80 ) : '';
		$extra     = array(
			'workspace' => (string) $workspace,
			'key_name'  => (string) $key_name,
		);
		if ( ! in_array( self::IMPORT_SCOPE, $scopes, true ) ) {
			/* translators: %s: name of the permission (scope) */
			return self::outcome( false, $code, sprintf( __( 'The API key is valid but lacks the permission %s. The import needs it.', 'reactive-chat' ), self::IMPORT_SCOPE ), $extra );
		}
		return self::outcome( true, $code, __( 'The API key is valid and may transfer order data.', 'reactive-chat' ), $extra );
	}

	/**
	 * A result array.
	 *
	 * @param bool   $ok      Success?
	 * @param int    $code    HTTP status (0 = none).
	 * @param string $message Message for the shop manager.
	 * @param array  $extra   Further display values.
	 * @return array
	 */
	private static function outcome( $ok, $code, $message, array $extra = array() ) {
		return array(
			'ok'      => (bool) $ok,
			'code'    => (int) $code,
			'message' => (string) $message,
		) + $extra;
	}

	// ---------------------------------------------------------------------
	// Status overview
	// ---------------------------------------------------------------------

	/**
	 * The rows of the status box.
	 *
	 * @return array[] Each: { state: ok|warning|error|off, label: string, text: string }
	 */
	public static function rows() {
		$rows     = array();
		$site_key = Reactive_Chat_Options::sanitize_site_key( Reactive_Chat_Options::get( 'site_key' ) );
		$mode     = (string) Reactive_Chat_Options::get( 'mode' );

		// Widget.
		if ( '' === $site_key ) {
			$rows[] = self::row( 'error', __( 'Chat widget', 'reactive-chat' ), __( 'Not shown: enter the site key of your reactive.chat project.', 'reactive-chat' ) );
		} elseif ( Reactive_Chat_Options::get( 'hide_launcher' ) ) {
			$rows[] = self::row( 'ok', __( 'Chat widget', 'reactive-chat' ), __( 'Loaded on your shop without the chat button - it opens from your own links (data-rc-open).', 'reactive-chat' ) );
		} else {
			$rows[] = self::row( 'ok', __( 'Chat widget', 'reactive-chat' ), __( 'Shown on your shop.', 'reactive-chat' ) );
		}

		// Consent.
		if ( 'wait' === Reactive_Chat_Options::get( 'consent_mode' ) ) {
			$source = (string) Reactive_Chat_Options::get( 'consent_source' );
			if ( 'wp_consent_api' === $source && ! function_exists( 'wp_has_consent' ) ) {
				$rows[] = self::row( 'error', __( 'Consent', 'reactive-chat' ), __( 'The widget waits for the WP Consent API, but that plugin is not active - the widget will never load.', 'reactive-chat' ) );
			} else {
				$labels = self::consent_source_labels();
				/* translators: %s: name of the consent source */
				$rows[] = self::row( 'ok', __( 'Consent', 'reactive-chat' ), sprintf( __( 'The widget loads only after consent (%s).', 'reactive-chat' ), isset( $labels[ $source ] ) ? $labels[ $source ] : $source ) );
			}
		} else {
			$rows[] = self::row( 'ok', __( 'Consent', 'reactive-chat' ), __( 'The widget loads on every page and asks your consent platform (IAB TCF v2 or consented.eu) before storing anything in the browser.', 'reactive-chat' ) );
		}

		// Identity.
		if ( '' !== Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_IDENT ) ) {
			$rows[] = self::row( 'ok', __( 'Signed-in customers', 'reactive-chat' ), __( 'Customer IDs are signed for one hour per page view.', 'reactive-chat' ) );
		} elseif ( 'aus' !== $mode ) {
			$rows[] = self::row( 'error', __( 'Signed-in customers', 'reactive-chat' ), __( 'No identity secret: reactive.chat cannot confirm customers, so the assistant never sees order data.', 'reactive-chat' ) );
		} else {
			$rows[] = self::row( 'off', __( 'Signed-in customers', 'reactive-chat' ), __( 'Not set up. Customers chat anonymously.', 'reactive-chat' ) );
		}

		// Order data on request.
		if ( Reactive_Chat_Options::pull_enabled() ) {
			$test = self::result( 'endpoint' );
			if ( '' === Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_ENDPOINT ) ) {
				$rows[] = self::row( 'error', __( 'Order data on request', 'reactive-chat' ), __( 'No endpoint secret: every request from reactive.chat is refused.', 'reactive-chat' ) );
			} elseif ( 0 !== strpos( Reactive_Chat_Rest::url(), 'https://' ) ) {
				$rows[] = self::row( 'error', __( 'Order data on request', 'reactive-chat' ), __( 'The endpoint address is not https - reactive.chat will not call it.', 'reactive-chat' ) );
			} elseif ( null === $test ) {
				$rows[] = self::row( 'warning', __( 'Order data on request', 'reactive-chat' ), __( 'Set up, not tested yet. Use "Test endpoint".', 'reactive-chat' ) );
			} else {
				$rows[] = self::row( $test['ok'] ? 'ok' : 'error', __( 'Order data on request', 'reactive-chat' ), self::dated( $test ) );
			}
		} else {
			$rows[] = self::row( 'off', __( 'Order data on request', 'reactive-chat' ), __( 'Off.', 'reactive-chat' ) );
		}

		// Import.
		if ( in_array( $mode, array( 'import', 'beide' ), true ) ) {
			$check  = self::result( 'api_key' );
			$status = get_option( Reactive_Chat_Options::IMPORT_STATUS, array() );
			if ( '' === Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_API_KEY ) ) {
				$rows[] = self::row( 'error', __( 'Order import', 'reactive-chat' ), __( 'No API key: nothing is transferred.', 'reactive-chat' ) );
			} elseif ( null !== $check && ! $check['ok'] ) {
				$rows[] = self::row( 'error', __( 'Order import', 'reactive-chat' ), self::dated( $check ) );
			} elseif ( is_array( $status ) && ! empty( $status['time'] ) ) {
				$code = isset( $status['code'] ) ? (int) $status['code'] : 0;
				$ok   = $code >= 200 && $code <= 299;
				$text = sprintf(
					/* translators: 1: date and time, 2: HTTP method, 3: HTTP status code, 4: error code */
					__( 'Last transfer: %1$s, %2$s, HTTP %3$d %4$s', 'reactive-chat' ),
					self::when( (int) $status['time'] ),
					isset( $status['method'] ) ? (string) $status['method'] : '',
					$code,
					isset( $status['error'] ) ? (string) $status['error'] : ''
				);
				if ( ! $ok && ! empty( $status['retry'] ) ) {
					$text .= ' ' . __( '(will be retried)', 'reactive-chat' );
				}
				if ( null !== $check && ! empty( $check['workspace'] ) ) {
					/* translators: %s: workspace name */
					$text .= ' ' . sprintf( __( 'Workspace: %s.', 'reactive-chat' ), (string) $check['workspace'] );
				}
				$rows[] = self::row( $ok ? 'ok' : 'error', __( 'Order import', 'reactive-chat' ), $text );
			} elseif ( null !== $check ) {
				$rows[] = self::row( 'ok', __( 'Order import', 'reactive-chat' ), self::dated( $check ) . ' ' . __( 'No transfer yet.', 'reactive-chat' ) );
			} else {
				$rows[] = self::row( 'warning', __( 'Order import', 'reactive-chat' ), __( 'Set up, key not checked yet. Use "Check API key".', 'reactive-chat' ) );
			}
		} else {
			$rows[] = self::row( 'off', __( 'Order import', 'reactive-chat' ), __( 'Off.', 'reactive-chat' ) );
		}
		return $rows;
	}

	/**
	 * Labels of the consent sources.
	 *
	 * @return array
	 */
	public static function consent_source_labels() {
		return array(
			'wp_consent_api' => __( 'WP Consent API', 'reactive-chat' ),
			'tcf'            => __( 'IAB TCF v2 consent platform (purpose 1)', 'reactive-chat' ),
			'event'          => __( 'JavaScript signal from your site', 'reactive-chat' ),
		);
	}

	/**
	 * One row.
	 *
	 * @param string $state ok|warning|error|off.
	 * @param string $label Label.
	 * @param string $text  Text.
	 * @return array
	 */
	private static function row( $state, $label, $text ) {
		return array(
			'state' => $state,
			'label' => $label,
			'text'  => $text,
		);
	}

	/**
	 * A stored result as text with its date.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	private static function dated( array $result ) {
		$text = isset( $result['message'] ) ? (string) $result['message'] : '';
		if ( ! empty( $result['workspace'] ) ) {
			/* translators: %s: workspace name */
			$text .= ' ' . sprintf( __( 'Workspace: %s.', 'reactive-chat' ), (string) $result['workspace'] );
		}
		if ( ! empty( $result['time'] ) ) {
			/* translators: %s: date and time */
			$text .= ' ' . sprintf( __( '(checked %s)', 'reactive-chat' ), self::when( (int) $result['time'] ) );
		}
		return $text;
	}

	/**
	 * Date and time in the site's format.
	 *
	 * @param int $time Unix seconds.
	 * @return string
	 */
	private static function when( $time ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}

<?php
/**
 * Settings and secrets.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two kinds of storage:
 *
 *   reactive_chat_settings   everything public in ONE option (autoload).
 *   reactive_chat_secret_*   one option per secret with autoload = no. A
 *                            secret is not loaded on every request, never
 *                            appears in the HTML and is never shown again on
 *                            the settings page - only "set" or "not set".
 */
final class Reactive_Chat_Options {

	const OPTION        = 'reactive_chat_settings';
	const IMPORT_STATUS = 'reactive_chat_import_status';

	const SECRET_IDENT    = 'ident';
	const SECRET_ENDPOINT = 'endpoint';
	const SECRET_API_KEY  = 'api_key';

	/** Option per secret. */
	const SECRET_OPTIONS = array(
		self::SECRET_IDENT    => 'reactive_chat_secret_ident',
		self::SECRET_ENDPOINT => 'reactive_chat_secret_endpoint',
		self::SECRET_API_KEY  => 'reactive_chat_secret_api_key',
	);

	/** Customer data modes, the same values as in the reactive.chat project (kd_modus). */
	const MODES = array( 'aus', 'abruf', 'import', 'beide' );

	/** Widget position. */
	const POSITIONS = array( 'right', 'left' );

	/** Widget colour scheme. */
	const THEMES = array( 'auto', 'light', 'dark' );

	/** Consent handling: let the widget ask the consent platform, or load it only after consent. */
	const CONSENT_MODES = array( 'auto', 'wait' );

	/** Where the consent signal comes from in the "wait" mode. */
	const CONSENT_SOURCES = array( 'wp_consent_api', 'tcf', 'event' );

	/** Categories of the WP Consent API. */
	const CONSENT_CATEGORIES = array( 'functional', 'preferences', 'statistics-anonymous', 'statistics', 'marketing' );

	const DEFAULT_BASE_URL = 'https://reactive.chat';
	const DEFAULT_CDN_BASE = 'https://cdn.reactive.chat';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'site_key'         => '',
			'mode'             => 'aus',
			'base_url'         => self::DEFAULT_BASE_URL,
			'project_id'       => '',
			'cdn_base'         => self::DEFAULT_CDN_BASE,
			'ws_url'           => '',
			'fields'           => null,
			'position'         => 'right',
			'theme'            => 'auto',
			'hide_launcher'    => false,
			'avoid_overlap'    => true,
			'hide_on_checkout' => false,
			'private_pages'    => true,
			'consent_mode'     => 'auto',
			'consent_source'   => 'wp_consent_api',
			'consent_category' => 'functional',
		);
	}

	/**
	 * All public settings, filled up with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key from defaults().
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Stores public settings (already sanitized).
	 *
	 * @param array $values Values.
	 */
	public static function save( array $values ) {
		$all = array_merge( self::all(), array_intersect_key( $values, self::defaults() ) );
		update_option( self::OPTION, $all );
	}

	/**
	 * The effective field selection.
	 *
	 * @return string[]
	 */
	public static function fields() {
		$selection = self::get( 'fields' );
		return Reactive_Chat_Schema::effective_fields( is_array( $selection ) ? $selection : null );
	}

	/**
	 * A secret in plain text ('' = none).
	 *
	 * @param string $name SECRET_*.
	 * @return string
	 */
	public static function secret( $name ) {
		if ( ! isset( self::SECRET_OPTIONS[ $name ] ) ) {
			return '';
		}
		$value = get_option( self::SECRET_OPTIONS[ $name ], '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Stores a secret - always with autoload = no.
	 *
	 * @param string $name  SECRET_*.
	 * @param string $value Plain text.
	 */
	public static function set_secret( $name, $value ) {
		if ( ! isset( self::SECRET_OPTIONS[ $name ] ) ) {
			return;
		}
		$option = self::SECRET_OPTIONS[ $name ];
		// update_option() only honours autoload reliably when the option is
		// created, so a missing option is added explicitly with 'no'.
		if ( false === get_option( $option, false ) ) {
			add_option( $option, $value, '', 'no' );
		} else {
			update_option( $option, $value, false );
		}
	}

	/**
	 * Removes a secret.
	 *
	 * @param string $name SECRET_*.
	 */
	public static function clear_secret( $name ) {
		if ( isset( self::SECRET_OPTIONS[ $name ] ) ) {
			delete_option( self::SECRET_OPTIONS[ $name ] );
		}
	}

	/**
	 * Is the customer data endpoint switched on?
	 *
	 * @return bool
	 */
	public static function pull_enabled() {
		return in_array( self::get( 'mode' ), array( 'abruf', 'beide' ), true );
	}

	/**
	 * Is the import switched on and completely set up?
	 *
	 * @return bool
	 */
	public static function push_enabled() {
		return in_array( self::get( 'mode' ), array( 'import', 'beide' ), true )
			&& '' !== self::secret( self::SECRET_API_KEY )
			&& '' !== (string) self::get( 'base_url' );
	}

	// ---------------------------------------------------------------------
	// Sanitizing (pure PHP apart from wp_parse_url).
	// ---------------------------------------------------------------------

	/**
	 * Site key: the pattern the widget loader accepts ([A-Za-z0-9_-]{8,64}),
	 * otherwise ''.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	public static function sanitize_site_key( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $value ) ? $value : '';
	}

	/**
	 * Mode from MODES, otherwise 'aus'.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	public static function sanitize_mode( $value ) {
		return is_string( $value ) && in_array( $value, self::MODES, true ) ? $value : 'aus';
	}

	/**
	 * A value from a fixed list, otherwise the first entry.
	 *
	 * @param mixed    $value   Input.
	 * @param string[] $allowed Allowed values; the first is the fallback.
	 * @return string
	 */
	public static function sanitize_choice( $value, array $allowed ) {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : (string) reset( $allowed );
	}

	/**
	 * A checkbox value.
	 *
	 * @param mixed $value Input.
	 * @return bool
	 */
	public static function sanitize_flag( $value ) {
		return ! empty( $value ) && '0' !== $value;
	}

	/**
	 * Project ID: positive integer as text, or ''.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	public static function sanitize_project_id( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return preg_match( '/^[1-9][0-9]{0,17}$/', $value ) ? $value : '';
	}

	/**
	 * An https (or wss) address without credentials, query and fragment, and
	 * without a trailing slash. Otherwise ''.
	 *
	 * @param mixed  $value   Input.
	 * @param string $schemes 'https' or 'wss'.
	 * @return string
	 */
	public static function sanitize_https_url( $value, $schemes = 'https' ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || strlen( $value ) > 300 || preg_match( '/[\x00-\x20\x7F"\'<>\\\\]/', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		if ( strtolower( $parts['scheme'] ) !== $schemes ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		return rtrim( $value, '/' );
	}

	/**
	 * A secret from the form: printable ASCII without white space, 16..256
	 * characters. Otherwise null (= invalid, do not store).
	 *
	 * @param mixed $value Input.
	 * @return string|null
	 */
	public static function sanitize_secret( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^[\x21-\x7E]{16,256}$/', $value ) ) {
			return $value;
		}
		return null;
	}
}

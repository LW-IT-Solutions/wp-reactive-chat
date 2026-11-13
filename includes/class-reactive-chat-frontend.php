<?php
/**
 * Widget loader, consent gate and identify() in the footer.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints in the footer exactly what the reactive.chat account shows as embed
 * code, plus the identity of a signed-in customer: first an inline queue
 * (window.ReactiveChat stub with ReactiveChat("identify", {...})), then the
 * loader.js tag of the CDN with data-rc-site-key and async.
 *
 * The queue comes BEFORE the loader: identify() lands in ReactiveChat.q and is
 * picked up by the loader.
 *
 * CONSENT. Mode "auto": the loader is printed directly and the widget asks
 * the consent platform itself (IAB TCF v2, consented.eu); without consent it
 * keeps nothing in localStorage. Mode "wait": the loader is NOT printed; a
 * small local script (assets/consent-gate.js) inserts it only after consent
 * from the WP Consent API, the IAB TCF v2 (purpose 1) or a JavaScript signal
 * of the site. Without consent no request goes to reactive.chat.
 *
 * IDENTITY: only for signed-in users and only with the identity secret.
 * userId = WordPress user ID, exp = now + 1 h,
 * userHash = HMAC(secret, userId . '.' . exp). The secret itself never
 * appears in the HTML. No e-mail, no name unless a developer opts in with the
 * filter reactive_chat_ident_email.
 *
 * PAGE CACHE: a page with identify() belongs to exactly one user. For
 * signed-in users the plugin therefore sets DONOTCACHEPAGE (respected by the
 * common page cache plugins); WordPress sends no-cache headers for them anyway.
 */
final class Reactive_Chat_Frontend {

	const GATE_HANDLE = 'reactive-chat-consent-gate';

	/** Set hooks. */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'no_page_cache' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	/** No page cache for pages with identify(). */
	public static function no_page_cache() {
		if ( is_user_logged_in() && '' !== Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_IDENT ) && function_exists( 'wc_maybe_define_constant' ) ) {
			// The constant page cache plugins respect; WooCommerce sets it the same way on cart and checkout.
			wc_maybe_define_constant( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * The fields for identify(), or null.
	 *
	 * @param int    $user_id User (0 = guest).
	 * @param string $secret  Identity secret.
	 * @param int    $now     Now, Unix seconds.
	 * @param int    $ttl     Lifetime in seconds (60 .. 86400).
	 * @param string $email   Empty (default) = no e-mail in identify(); otherwise
	 *                        it is signed as well (ident_hash_email).
	 * @return array|null
	 */
	public static function identity( $user_id, $secret, $now, $ttl = Reactive_Chat_Signature::IDENT_TTL, $email = '' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! is_string( $secret ) || '' === $secret ) {
			return null;
		}
		$id    = (string) $user_id;
		$exp   = (int) $now + max( 60, min( (int) $ttl, Reactive_Chat_Signature::IDENT_TTL_MAX ) );
		$email = is_string( $email ) ? trim( $email ) : '';
		if ( '' !== $email && preg_match( '/^[^\s@<>"]{1,64}@[^\s@<>"]{1,190}\.[A-Za-z]{2,24}$/', $email ) ) {
			return array(
				'userId'   => $id,
				'email'    => $email,
				'userHash' => Reactive_Chat_Signature::ident_hash_email( $secret, $id, $exp, $email ),
				'exp'      => $exp,
			);
		}
		return array(
			'userId'   => $id,
			'userHash' => Reactive_Chat_Signature::ident_hash( $secret, $id, $exp ),
			'exp'      => $exp,
		);
	}

	/**
	 * Is this a page whose text must not be shared with the assistant
	 * (cart, checkout, My Account, order confirmation)?
	 *
	 * @return bool
	 */
	public static function is_private_page() {
		$private = false;
		if ( function_exists( 'is_account_page' ) ) {
			$private = is_account_page() || is_cart() || is_checkout() || ( function_exists( 'is_order_received_page' ) && is_order_received_page() );
		}
		/**
		 * Whether the current page counts as private: the widget then sends no
		 * page text to the assistant and "Show me" is off on this page.
		 *
		 * @param bool $private Private page?
		 */
		return (bool) apply_filters( 'reactive_chat_is_private_page', $private );
	}

	/**
	 * Should the widget be loaded on this request at all?
	 *
	 * @return bool
	 */
	public static function should_load() {
		if ( '' === Reactive_Chat_Options::sanitize_site_key( Reactive_Chat_Options::get( 'site_key' ) ) ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}
		$load = true;
		if ( Reactive_Chat_Options::get( 'hide_on_checkout' ) && function_exists( 'is_checkout' ) && is_checkout()
			&& ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			$load = false;
		}
		/**
		 * Whether the chat widget is loaded on this request. Use it to hide the
		 * widget on certain pages or to follow a server-side consent decision of
		 * your consent management platform.
		 *
		 * @param bool $load Load the widget?
		 */
		return (bool) apply_filters( 'reactive_chat_load_widget', $load );
	}

	/**
	 * The address of loader.js.
	 *
	 * @return string
	 */
	public static function loader_src() {
		$cdn = Reactive_Chat_Options::sanitize_https_url( Reactive_Chat_Options::get( 'cdn_base' ) );
		if ( '' === $cdn ) {
			$cdn = Reactive_Chat_Options::DEFAULT_CDN_BASE;
		}
		return $cdn . '/loader.js';
	}

	/**
	 * The data-rc-* attributes of the loader tag.
	 *
	 * @param bool $private_page Private page (no page text to the assistant)?
	 * @return array Attribute => value (strings only).
	 */
	public static function loader_attributes( $private_page ) {
		$attributes = array(
			'data-rc-site-key' => Reactive_Chat_Options::sanitize_site_key( Reactive_Chat_Options::get( 'site_key' ) ),
		);
		$ws = Reactive_Chat_Options::sanitize_https_url( Reactive_Chat_Options::get( 'ws_url' ), 'wss' );
		if ( '' !== $ws ) {
			$attributes['data-rc-ws-url'] = $ws . '/';
		}
		if ( 'left' === Reactive_Chat_Options::get( 'position' ) ) {
			$attributes['data-rc-position'] = 'left';
		}
		$theme = (string) Reactive_Chat_Options::get( 'theme' );
		if ( in_array( $theme, array( 'light', 'dark' ), true ) ) {
			$attributes['data-rc-theme'] = $theme;
		}
		if ( Reactive_Chat_Options::get( 'hide_launcher' ) ) {
			$attributes['data-rc-hide-launcher'] = 'true';
		}
		if ( ! Reactive_Chat_Options::get( 'avoid_overlap' ) ) {
			$attributes['data-rc-avoid-overlap'] = 'false';
		}
		if ( $private_page ) {
			$attributes['data-rc-dom-spiegel'] = 'false';
		}

		/**
		 * Additional data-rc-* attributes for the loader tag (see the script
		 * tag reference of reactive.chat). Only names matching data-rc-[a-z-]+
		 * with scalar values are used; the site key cannot be changed here.
		 *
		 * @param array $attributes Attribute => value.
		 */
		$extra = apply_filters( 'reactive_chat_loader_attributes', $attributes );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $name => $value ) {
				if ( is_string( $name ) && 'data-rc-site-key' !== $name && preg_match( '/^data-rc-[a-z][a-z0-9-]{0,40}$/', $name ) && is_scalar( $value ) ) {
					$attributes[ $name ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				}
			}
		}
		return $attributes;
	}

	/**
	 * The inline queue script (ReactiveChat stub plus identify()).
	 *
	 * @return string
	 */
	public static function queue_script() {
		$script = 'window.ReactiveChat=window.ReactiveChat||function(){(ReactiveChat.q=ReactiveChat.q||[]).push(arguments)};';
		/**
		 * Lifetime of the userHash in seconds (default 3600, at most 86400).
		 * reactive.chat treats a visitor as not confirmed after exp - a tab that
		 * stays open longer only gets order data again after the next page view.
		 *
		 * @param int $ttl Seconds.
		 */
		$ttl = (int) apply_filters( 'reactive_chat_ident_ttl', Reactive_Chat_Signature::IDENT_TTL );
		/**
		 * Include the customer's e-mail address, signed, in identify() (default:
		 * no). Needed if the assistant books appointments or an integration
		 * looks up the e-mail address; reactive.chat does not accept an unsigned
		 * address as confirmed. The address then appears in the HTML of this
		 * customer's pages.
		 *
		 * @param bool $include true = include and sign.
		 */
		$email = '';
		if ( true === apply_filters( 'reactive_chat_ident_email', false ) ) {
			$user  = wp_get_current_user();
			$email = is_object( $user ) && isset( $user->user_email ) ? (string) $user->user_email : '';
		}
		$ident = self::identity( get_current_user_id(), Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_IDENT ), time(), $ttl, $email );
		if ( null !== $ident ) {
			// JSON_HEX_*: no "</script>" and no quotes from a value can leave the script.
			$script .= 'ReactiveChat("identify",' . wp_json_encode( $ident, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');';
		}
		return $script;
	}

	/** Consent mode "wait": enqueue the local gate script with its settings. */
	public static function enqueue() {
		if ( 'wait' !== Reactive_Chat_Options::get( 'consent_mode' ) || ! self::should_load() ) {
			return;
		}
		$attributes = self::loader_attributes( self::is_private_page() );
		$config     = array(
			'src'      => self::loader_src(),
			'attrs'    => $attributes,
			'source'   => Reactive_Chat_Options::sanitize_choice( Reactive_Chat_Options::get( 'consent_source' ), Reactive_Chat_Options::CONSENT_SOURCES ),
			'category' => Reactive_Chat_Options::sanitize_choice( Reactive_Chat_Options::get( 'consent_category' ), Reactive_Chat_Options::CONSENT_CATEGORIES ),
		);
		wp_enqueue_script(
			self::GATE_HANDLE,
			plugins_url( 'assets/consent-gate.js', REACTIVE_CHAT_FILE ),
			array(),
			REACTIVE_CHAT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::GATE_HANDLE,
			self::queue_script() . 'window.reactiveChatGate=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}

	/** Consent mode "auto": queue and loader tag in the footer. */
	public static function render() {
		if ( 'wait' === Reactive_Chat_Options::get( 'consent_mode' ) || ! self::should_load() ) {
			return;
		}
		wp_print_inline_script_tag( self::queue_script(), array( 'id' => 'reactive-chat-queue' ) );

		$attributes = array(
			'src'   => self::loader_src(),
			'async' => true,
			'id'    => 'reactive-chat-loader',
		) + self::loader_attributes( self::is_private_page() );
		wp_print_script_tag( $attributes );
	}
}

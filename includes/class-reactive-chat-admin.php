<?php
/**
 * Settings page: WooCommerce -> reactive.chat.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Own form posting to admin-post.php instead of the Settings API: secrets
 * follow other rules than normal options (empty = unchanged, never shown
 * again, own checkbox to remove), and the page belongs to shop managers
 * (manage_woocommerce), not only to administrators (manage_options, the
 * default of the Settings API).
 *
 * Messages after an action travel in a short-lived per-user transient, not in
 * the URL.
 */
final class Reactive_Chat_Admin {

	const PAGE        = 'reactive-chat';
	const CAPABILITY  = 'manage_woocommerce';
	const ACTION_SAVE = 'reactive_chat_save';
	const ACTION_BULK = 'reactive_chat_bulk';
	const NOTICE      = 'reactive_chat_notice_';

	/** Hook suffix of the settings page. */
	private static $hook = '';

	/** Set hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . self::ACTION_BULK, array( __CLASS__, 'bulk' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( REACTIVE_CHAT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Menu entry under WooCommerce. */
	public static function menu() {
		self::$hook = (string) add_submenu_page( 'woocommerce', 'reactive.chat', 'reactive.chat', self::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ) );
		if ( '' !== self::$hook ) {
			add_action( 'load-' . self::$hook, array( __CLASS__, 'help_tabs' ) );
		}
	}

	/**
	 * "Settings" in the plugin list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'reactive-chat' ) . '</a>' );
		return $links;
	}

	/**
	 * Styles and script of the settings page - only there.
	 *
	 * @param string $hook Hook suffix of the current page.
	 */
	public static function assets( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'reactive-chat-admin', plugins_url( 'assets/admin.css', REACTIVE_CHAT_FILE ), array(), REACTIVE_CHAT_VERSION );
		wp_enqueue_script(
			'reactive-chat-admin',
			plugins_url( 'assets/admin.js', REACTIVE_CHAT_FILE ),
			array(),
			REACTIVE_CHAT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'reactive-chat-admin',
			'reactiveChatAdmin',
			array(
				'copied' => __( 'Copied', 'reactive-chat' ),
				'copy'   => __( 'Copy', 'reactive-chat' ),
			)
		);
	}

	/** Help tabs of the settings page. */
	public static function help_tabs() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'reactive-chat-overview',
				'title'   => __( 'Overview', 'reactive-chat' ),
				'content' => '<p>' . esc_html__( 'reactive.chat is a live chat with an AI assistant and human agents. This plugin embeds the chat widget in your shop. Optionally it confirms signed-in customers so that the assistant can answer questions about their own orders.', 'reactive-chat' ) . '</p><p>' . esc_html__( 'You need a reactive.chat account. Order data in the chat requires a paid plan at reactive.chat.', 'reactive-chat' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'reactive-chat-consent',
				'title'   => __( 'Consent', 'reactive-chat' ),
				'content' => '<p>' . esc_html__( 'Automatic: the widget loads on every page and checks your consent platform (IAB TCF v2 or consented.eu) itself; without consent it keeps its data only for the current tab.', 'reactive-chat' ) . '</p><p>' . esc_html__( 'Only after consent: no request goes to reactive.chat until consent is given through the WP Consent API, an IAB TCF v2 platform or a JavaScript signal of your site:', 'reactive-chat' ) . '</p><p><code>window.reactiveChatConsent( true );</code></p><p>' . esc_html__( 'Developers can also hide the widget server-side with the filter reactive_chat_load_widget.', 'reactive-chat' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'reactive-chat-security',
				'title'   => __( 'Order data security', 'reactive-chat' ),
				'content' => '<p>' . esc_html__( 'The assistant only ever sees orders of the signed-in customer who is asking. The plugin signs the customer ID with the identity secret; reactive.chat asks the endpoint of this shop only for such a confirmed customer, and every request is signed with the endpoint secret, valid for five minutes and accepted only once.', 'reactive-chat' ) . '</p><p>' . esc_html__( 'Names, addresses, e-mail and phone numbers, payment data and customer notes are never transmitted. An order number typed into the chat is only a filter within the customer\'s own orders.', 'reactive-chat' ) . '</p>',
			)
		);
		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'More information', 'reactive-chat' ) . '</strong></p>' .
			'<p><a href="' . esc_url( Reactive_Chat_Options::DEFAULT_BASE_URL . '/en/p/docs' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'reactive-chat' ) . '</a></p>' .
			'<p><a href="' . esc_url( Reactive_Chat_Options::DEFAULT_BASE_URL . '/en/p/privacy-policy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy policy', 'reactive-chat' ) . '</a></p>'
		);
	}

	/** Suggested text for the privacy policy (Settings > Privacy). */
	public static function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Adapt this text to your settings: consent mode, signed-in customers and order data.', 'reactive-chat' ) . '</p>';
		$text .= '<p>' . esc_html__( 'This shop uses the chat service reactive.chat (LW IT Solutions, Lukas Wójcik). When the chat loads, your browser connects to cdn.reactive.chat and ws.reactive.chat; your IP address and technical browser data are processed for this. Messages you write in the chat, the address of the page and - unless switched off - the visible text of the page are processed by reactive.chat and its AI assistant to answer you.', 'reactive-chat' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you are signed in to your customer account, the shop sends a signed customer number to the chat. The assistant can then look up your own orders in this shop: order number, date, status, total, product names and quantities, shipping carrier, tracking number and invoice status. Names, addresses, e-mail addresses, phone numbers and payment data are not transmitted.', 'reactive-chat' ) . '</p>';
		/* translators: %s: address of the reactive.chat privacy policy */
		$text .= '<p>' . esc_html( sprintf( __( 'Details: %s', 'reactive-chat' ), Reactive_Chat_Options::DEFAULT_BASE_URL . '/en/p/privacy-policy' ) ) . '</p>';
		wp_add_privacy_policy_content( 'reactive.chat for WooCommerce', wp_kses_post( wpautop( $text, false ) ) );
	}

	// ---------------------------------------------------------------------
	// Notices and redirects
	// ---------------------------------------------------------------------

	/**
	 * Queues a notice for the current user's next view of the page.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Text.
	 */
	public static function add_notice( $type, $message ) {
		$key     = self::NOTICE . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => (string) $message,
		);
		set_transient( $key, $notices, 120 );
	}

	/**
	 * Back to the settings page and stop.
	 *
	 * @param string $anchor Section to jump to.
	 */
	public static function redirect_back( $anchor = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		if ( '' !== $anchor ) {
			$url .= '#' . rawurlencode( $anchor );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/** Prints and clears the queued notices. */
	private static function notices() {
		$key     = self::NOTICE . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
				continue;
			}
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	// ---------------------------------------------------------------------
	// Labels
	// ---------------------------------------------------------------------

	/**
	 * Labels of the catalogue.
	 *
	 * @return array Path => label.
	 */
	public static function field_labels() {
		return array(
			'bestellung.nummer'      => __( 'Order number (always on)', 'reactive-chat' ),
			'bestellung.datum'       => __( 'Order date', 'reactive-chat' ),
			'bestellung.status'      => __( 'Order status', 'reactive-chat' ),
			'bestellung.summe'       => __( 'Order total', 'reactive-chat' ),
			'bestellung.waehrung'    => __( 'Currency (only with total)', 'reactive-chat' ),
			'positionen.name'        => __( 'Product names', 'reactive-chat' ),
			'positionen.menge'       => __( 'Quantities', 'reactive-chat' ),
			'versand.dienst'         => __( 'Shipping carrier', 'reactive-chat' ),
			'versand.sendungsnummer' => __( 'Tracking number', 'reactive-chat' ),
			'versand.status'         => __( 'Shipping status', 'reactive-chat' ),
			'versand.ort'            => __( 'Delivery city (part of the address, off by default)', 'reactive-chat' ),
			'versand.plz'            => __( 'Delivery postcode (part of the address, off by default)', 'reactive-chat' ),
			'rechnung.nummer'        => __( 'Invoice number', 'reactive-chat' ),
			'rechnung.status'        => __( 'Payment status', 'reactive-chat' ),
			'rechnung.faellig_am'    => __( 'Invoice due date', 'reactive-chat' ),
		);
	}

	/**
	 * Labels of the block list.
	 *
	 * @return array Kind => label.
	 */
	public static function blocklist_labels() {
		return array(
			'anschrift'  => __( 'Street, house number, address lines', 'reactive-chat' ),
			'empfaenger' => __( 'Name of the recipient or customer', 'reactive-chat' ),
			'email'      => __( 'E-mail address', 'reactive-chat' ),
			'telefon'    => __( 'Phone and mobile numbers', 'reactive-chat' ),
			'bank'       => __( 'IBAN, BIC, account number', 'reactive-chat' ),
			'karte'      => __( 'Card number, expiry date, security code', 'reactive-chat' ),
			'geburt'     => __( 'Date of birth', 'reactive-chat' ),
			'zugang'     => __( 'Passwords, tokens, sessions, API keys', 'reactive-chat' ),
			'steuer'     => __( 'Tax number, VAT ID, ID card and passport numbers', 'reactive-chat' ),
		);
	}

	/**
	 * Labels of the modes.
	 *
	 * @return array
	 */
	public static function mode_labels() {
		return array(
			'aus'    => __( 'Off - no order data', 'reactive-chat' ),
			'abruf'  => __( 'On request - reactive.chat asks this shop per question (recommended)', 'reactive-chat' ),
			'import' => __( 'Import - this shop pushes orders to reactive.chat', 'reactive-chat' ),
			'beide'  => __( 'Both - import first, otherwise ask this shop', 'reactive-chat' ),
		);
	}

	/**
	 * Labels of the WP Consent API categories.
	 *
	 * @return array
	 */
	public static function category_labels() {
		return array(
			'functional'           => __( 'Functional', 'reactive-chat' ),
			'preferences'          => __( 'Preferences', 'reactive-chat' ),
			'statistics-anonymous' => __( 'Statistics (anonymous)', 'reactive-chat' ),
			'statistics'           => __( 'Statistics', 'reactive-chat' ),
			'marketing'            => __( 'Marketing', 'reactive-chat' ),
		);
	}

	// ---------------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------------

	/** Save. */
	public static function save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'reactive-chat' ), 403 );
		}
		check_admin_referer( self::ACTION_SAVE );

		$before = Reactive_Chat_Options::all();
		$errors = array();
		$values = array();

		$site_key           = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : '';
		$values['site_key'] = Reactive_Chat_Options::sanitize_site_key( $site_key );
		if ( '' !== trim( $site_key ) && '' === $values['site_key'] ) {
			$errors[] = __( 'Site key', 'reactive-chat' );
		}
		$values['mode']       = Reactive_Chat_Options::sanitize_mode( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '' );
		$values['project_id'] = Reactive_Chat_Options::sanitize_project_id( isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '' );

		$values['position']         = Reactive_Chat_Options::sanitize_choice( isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : '', Reactive_Chat_Options::POSITIONS );
		$values['theme']            = Reactive_Chat_Options::sanitize_choice( isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '', Reactive_Chat_Options::THEMES );
		$values['consent_mode']     = Reactive_Chat_Options::sanitize_choice( isset( $_POST['consent_mode'] ) ? sanitize_key( wp_unslash( $_POST['consent_mode'] ) ) : '', Reactive_Chat_Options::CONSENT_MODES );
		$values['consent_source']   = Reactive_Chat_Options::sanitize_choice( isset( $_POST['consent_source'] ) ? sanitize_key( wp_unslash( $_POST['consent_source'] ) ) : '', Reactive_Chat_Options::CONSENT_SOURCES );
		$values['consent_category'] = Reactive_Chat_Options::sanitize_choice( isset( $_POST['consent_category'] ) ? sanitize_key( wp_unslash( $_POST['consent_category'] ) ) : '', Reactive_Chat_Options::CONSENT_CATEGORIES );
		foreach ( array( 'hide_launcher', 'avoid_overlap', 'hide_on_checkout', 'private_pages' ) as $flag ) {
			$values[ $flag ] = Reactive_Chat_Options::sanitize_flag( isset( $_POST[ $flag ] ) ? sanitize_key( wp_unslash( $_POST[ $flag ] ) ) : '' );
		}

		$urls = array(
			'base_url' => array( 'https', Reactive_Chat_Options::DEFAULT_BASE_URL, __( 'reactive.chat address', 'reactive-chat' ) ),
			'cdn_base' => array( 'https', Reactive_Chat_Options::DEFAULT_CDN_BASE, __( 'Widget address (CDN)', 'reactive-chat' ) ),
			'ws_url'   => array( 'wss', '', __( 'WebSocket address', 'reactive-chat' ) ),
		);
		foreach ( $urls as $key => $spec ) {
			$raw   = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
			$clean = '' === $raw ? $spec[1] : Reactive_Chat_Options::sanitize_https_url( $raw, $spec[0] );
			if ( '' !== $raw && '' === $clean ) {
				$errors[] = $spec[2];
				continue;
			}
			$values[ $key ] = $clean;
		}

		$selection        = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['fields'] ) ) : array();
		$values['fields'] = Reactive_Chat_Schema::sanitize_selection( $selection );

		Reactive_Chat_Options::save( $values );

		// Secrets: empty = unchanged; checkbox = remove; otherwise set.
		$secret_labels  = array(
			Reactive_Chat_Options::SECRET_IDENT    => __( 'Identity secret', 'reactive-chat' ),
			Reactive_Chat_Options::SECRET_ENDPOINT => __( 'Endpoint secret', 'reactive-chat' ),
			Reactive_Chat_Options::SECRET_API_KEY  => __( 'API key', 'reactive-chat' ),
		);
		$secret_changed = array();
		foreach ( $secret_labels as $name => $label ) {
			if ( ! empty( $_POST[ 'clear_' . $name ] ) ) {
				Reactive_Chat_Options::clear_secret( $name );
				$secret_changed[ $name ] = true;
				continue;
			}
			// reactive.chat secrets and keys are prefixed hex strings, which
			// sanitize_text_field() leaves untouched; sanitize_secret() then
			// accepts printable ASCII without white space only.
			$input = isset( $_POST[ 'secret_' . $name ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'secret_' . $name ] ) ) ) : '';
			if ( '' === $input ) {
				continue;
			}
			$clean = Reactive_Chat_Options::sanitize_secret( $input );
			if ( null === $clean ) {
				$errors[] = $label;
				continue;
			}
			Reactive_Chat_Options::set_secret( $name, $clean );
			$secret_changed[ $name ] = true;
		}

		// Stored test results no longer describe the new configuration.
		$after = Reactive_Chat_Options::all();
		if ( isset( $secret_changed[ Reactive_Chat_Options::SECRET_ENDPOINT ] ) || $before['mode'] !== $after['mode']
			|| isset( $secret_changed[ Reactive_Chat_Options::SECRET_API_KEY ] ) || $before['base_url'] !== $after['base_url'] ) {
			Reactive_Chat_Diagnostics::forget();
		}

		if ( array() === $errors ) {
			self::add_notice( 'success', __( 'Settings saved.', 'reactive-chat' ) );
		} else {
			self::add_notice( 'error', __( 'Saved, except these invalid values (they were not changed):', 'reactive-chat' ) . ' ' . implode( ', ', $errors ) );
		}
		self::redirect_back();
	}

	/** Transfer all customers. */
	public static function bulk() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'reactive-chat' ), 403 );
		}
		check_admin_referer( self::ACTION_BULK );
		if ( Reactive_Chat_Import::queue_bulk() ) {
			self::add_notice( 'success', __( 'Transfer of all customers with orders from the last 90 days has been queued.', 'reactive-chat' ) );
		} else {
			self::add_notice( 'error', __( 'Import is not set up: choose the mode Import or Both and enter an API key.', 'reactive-chat' ) );
		}
		self::redirect_back();
	}

	// ---------------------------------------------------------------------
	// Form parts
	// ---------------------------------------------------------------------

	/**
	 * Start of a form row.
	 *
	 * @param string $id    Field ID ('' = no label target).
	 * @param string $label Label.
	 * @param string $class Extra row class.
	 */
	private static function row_start( $id, $label, $class = '' ) {
		echo '<tr' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '><th scope="row">';
		if ( '' !== $id ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo esc_html( $label );
		}
		echo '</th><td>';
	}

	/**
	 * A help text below a field.
	 *
	 * @param string $help Text.
	 * @param string $id   ID for aria-describedby.
	 */
	private static function help( $help, $id = '' ) {
		if ( '' !== $help ) {
			echo '<p class="description"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * A password field for a secret - never with a value.
	 *
	 * @param string $name  SECRET_*.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @param string $class Extra row class.
	 */
	private static function secret_row( $name, $label, $help, $class = '' ) {
		$id  = 'rc-secret-' . $name;
		$set = '' !== Reactive_Chat_Options::secret( $name );
		self::row_start( $id, $label, $class );
		echo '<input type="password" class="regular-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'secret_' . $name ) . '" value="" autocomplete="new-password" spellcheck="false" aria-describedby="' . esc_attr( $id . '-help' ) . '" placeholder="' . esc_attr( $set ? __( 'Saved - leave empty to keep it', 'reactive-chat' ) : '' ) . '" />';
		echo ' <span class="rc-badge ' . ( $set ? 'rc-badge-ok' : 'rc-badge-off' ) . '">' . esc_html( $set ? __( 'Set', 'reactive-chat' ) : __( 'Not set', 'reactive-chat' ) ) . '</span>';
		self::help( $help, $id . '-help' );
		if ( $set ) {
			echo '<label class="rc-remove"><input type="checkbox" name="' . esc_attr( 'clear_' . $name ) . '" value="1" /> ' . esc_html__( 'Remove the saved value', 'reactive-chat' ) . '</label>';
		}
		echo '</td></tr>';
	}

	/**
	 * A text field.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Label.
	 * @param string $help        Help text.
	 * @param string $type        Field type.
	 * @param string $placeholder Placeholder.
	 * @param string $class       Extra row class.
	 */
	private static function text_row( $key, $label, $help, $type = 'text', $placeholder = '', $class = '' ) {
		$id = 'rc-' . str_replace( '_', '-', $key );
		self::row_start( $id, $label, $class );
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) Reactive_Chat_Options::get( $key ) ) . '" placeholder="' . esc_attr( $placeholder ) . '" spellcheck="false" aria-describedby="' . esc_attr( $id . '-help' ) . '" />';
		self::help( $help, $id . '-help' );
		echo '</td></tr>';
	}

	/**
	 * A select field.
	 *
	 * @param string $key     Option key.
	 * @param string $label   Label.
	 * @param array  $choices Value => label.
	 * @param string $help    Help text.
	 * @param string $class   Extra row class.
	 */
	private static function select_row( $key, $label, array $choices, $help = '', $class = '' ) {
		$id      = 'rc-' . str_replace( '_', '-', $key );
		$current = (string) Reactive_Chat_Options::get( $key );
		self::row_start( $id, $label, $class );
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $id . '-help' ) . '">';
		foreach ( $choices as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
		self::help( $help, $id . '-help' );
		echo '</td></tr>';
	}

	/**
	 * A checkbox.
	 *
	 * @param string $key   Option key.
	 * @param string $label Row label.
	 * @param string $text  Text next to the box.
	 * @param string $help  Help text.
	 */
	private static function checkbox_row( $key, $label, $text, $help = '' ) {
		$id = 'rc-' . str_replace( '_', '-', $key );
		self::row_start( '', $label );
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) Reactive_Chat_Options::get( $key ), true, false ) . ' /> ' . esc_html( $text ) . '</label>';
		self::help( $help );
		echo '</td></tr>';
	}

	/**
	 * A small form with one button posting to admin-post.php.
	 *
	 * @param string $action  Action.
	 * @param string $label   Button text.
	 * @param bool   $enabled Enabled?
	 */
	private static function action_button( $action, $label, $enabled = true ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rc-inline-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<button type="submit" class="button"' . disabled( $enabled, false, false ) . '>' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	// ---------------------------------------------------------------------
	// The page
	// ---------------------------------------------------------------------

	/** The page. */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$mode       = (string) Reactive_Chat_Options::get( 'mode' );
		$fields     = Reactive_Chat_Options::fields();
		$endpoint   = Reactive_Chat_Rest::url();
		$configured = '' !== Reactive_Chat_Options::sanitize_site_key( Reactive_Chat_Options::get( 'site_key' ) );

		echo '<div class="wrap rc-admin">';
		echo '<h1 class="rc-title"><span class="rc-logo" aria-hidden="true">rc</span> ' . esc_html__( 'reactive.chat for WooCommerce', 'reactive-chat' ) . '</h1>';
		self::notices();

		echo '<nav class="rc-nav" aria-label="' . esc_attr__( 'Sections', 'reactive-chat' ) . '"><ul>';
		$sections = array(
			'rc-status'   => __( 'Status', 'reactive-chat' ),
			'rc-widget'   => __( 'Chat widget', 'reactive-chat' ),
			'rc-consent'  => __( 'Privacy and consent', 'reactive-chat' ),
			'rc-identity' => __( 'Signed-in customers', 'reactive-chat' ),
			'rc-orders'   => __( 'Order data', 'reactive-chat' ),
			'rc-advanced' => __( 'Advanced', 'reactive-chat' ),
		);
		foreach ( $sections as $anchor => $title ) {
			echo '<li><a href="#' . esc_attr( $anchor ) . '">' . esc_html( $title ) . '</a></li>';
		}
		echo '</ul></nav>';

		echo '<div class="rc-columns"><div class="rc-main">';

		// --- Status ------------------------------------------------------
		echo '<section class="rc-card" id="rc-status"><h2>' . esc_html__( 'Status', 'reactive-chat' ) . '</h2>';
		echo '<table class="rc-status widefat striped" role="presentation"><tbody>';
		$states = array(
			'ok'      => array( 'dashicons-yes-alt', __( 'OK', 'reactive-chat' ) ),
			'warning' => array( 'dashicons-warning', __( 'Attention', 'reactive-chat' ) ),
			'error'   => array( 'dashicons-dismiss', __( 'Problem', 'reactive-chat' ) ),
			'off'     => array( 'dashicons-minus', __( 'Off', 'reactive-chat' ) ),
		);
		foreach ( Reactive_Chat_Diagnostics::rows() as $row ) {
			$state = isset( $states[ $row['state'] ] ) ? $row['state'] : 'off';
			echo '<tr class="rc-state-' . esc_attr( $state ) . '"><th scope="row"><span class="dashicons ' . esc_attr( $states[ $state ][0] ) . '" aria-hidden="true"></span> ' . esc_html( $row['label'] ) . '</th>';
			echo '<td><span class="screen-reader-text">' . esc_html( $states[ $state ][1] ) . ': </span>' . esc_html( $row['text'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<div class="rc-actions">';
		self::action_button( Reactive_Chat_Diagnostics::ACTION_ENDPOINT, __( 'Test endpoint', 'reactive-chat' ), Reactive_Chat_Options::pull_enabled() && '' !== Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_ENDPOINT ) );
		self::action_button( Reactive_Chat_Diagnostics::ACTION_KEY, __( 'Check API key', 'reactive-chat' ), '' !== Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_API_KEY ) );
		echo '</div></section>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rc-settings">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		wp_nonce_field( self::ACTION_SAVE );

		// --- Widget -------------------------------------------------------
		echo '<section class="rc-card" id="rc-widget"><h2>' . esc_html__( 'Chat widget', 'reactive-chat' ) . '</h2>';
		echo '<p>' . esc_html__( 'Design, texts, opening hours, the assistant and its knowledge are set in your reactive.chat account. The settings here apply to this shop only.', 'reactive-chat' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::text_row( 'site_key', __( 'Site key', 'reactive-chat' ), __( 'In your reactive.chat account under Project > Installation (data-rc-site-key in the snippet). Without a site key no widget is shown.', 'reactive-chat' ), 'text', 'e.g. A1B2C3D4E5F6G7H8J9K0M1N2' );
		self::select_row(
			'position',
			__( 'Position', 'reactive-chat' ),
			array(
				'right' => __( 'Bottom right', 'reactive-chat' ),
				'left'  => __( 'Bottom left', 'reactive-chat' ),
			)
		);
		self::select_row(
			'theme',
			__( 'Colour scheme', 'reactive-chat' ),
			array(
				'auto'  => __( 'Automatic (follows the page and the system)', 'reactive-chat' ),
				'light' => __( 'Light', 'reactive-chat' ),
				'dark'  => __( 'Dark', 'reactive-chat' ),
			)
		);
		self::checkbox_row( 'hide_launcher', __( 'Chat button', 'reactive-chat' ), __( 'Hide the chat button', 'reactive-chat' ), __( 'The chat then opens only from your own links or buttons, e.g. <a href="#rc-open"> or any element with the attribute data-rc-open.', 'reactive-chat' ) );
		self::checkbox_row( 'avoid_overlap', __( 'Fixed bars', 'reactive-chat' ), __( 'Move out of the way of fixed bars at the bottom edge (cookie banner, cart bar)', 'reactive-chat' ) );
		self::checkbox_row( 'hide_on_checkout', __( 'Checkout', 'reactive-chat' ), __( 'Do not load the chat on the checkout page', 'reactive-chat' ), __( 'Works with the classic and the block checkout. The order confirmation page still shows the chat.', 'reactive-chat' ) );
		echo '</table></section>';

		// --- Consent ------------------------------------------------------
		$consent_mode = (string) Reactive_Chat_Options::get( 'consent_mode' );
		echo '<section class="rc-card" id="rc-consent"><h2>' . esc_html__( 'Privacy and consent', 'reactive-chat' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::row_start( '', __( 'Loading the widget', 'reactive-chat' ) );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Loading the widget', 'reactive-chat' ) . '</legend>';
		echo '<label class="rc-radio"><input type="radio" name="consent_mode" value="auto"' . checked( $consent_mode, 'auto', false ) . ' /> <strong>' . esc_html__( 'Automatic', 'reactive-chat' ) . '</strong> - ' . esc_html__( 'load on every page; the widget asks your consent platform (IAB TCF v2 or consented.eu) and stores nothing permanently without consent.', 'reactive-chat' ) . '</label>';
		echo '<label class="rc-radio"><input type="radio" name="consent_mode" value="wait"' . checked( $consent_mode, 'wait', false ) . ' /> <strong>' . esc_html__( 'Only after consent', 'reactive-chat' ) . '</strong> - ' . esc_html__( 'no connection to reactive.chat until the visitor has consented.', 'reactive-chat' ) . '</label>';
		echo '</fieldset></td></tr>';
		self::select_row( 'consent_source', __( 'Consent source', 'reactive-chat' ), Reactive_Chat_Diagnostics::consent_source_labels(), __( 'WP Consent API: works with consent plugins that support it (e.g. Complianz, CookieYes, Cookiebot). JavaScript signal: call window.reactiveChatConsent( true ) from your consent tool.', 'reactive-chat' ), 'rc-wait-only' );
		self::select_row( 'consent_category', __( 'Consent category', 'reactive-chat' ), self::category_labels(), __( 'The WP Consent API category the visitor has to accept.', 'reactive-chat' ), 'rc-wait-only' );
		self::checkbox_row( 'private_pages', __( 'Private pages', 'reactive-chat' ), __( 'Do not share page text with the assistant on cart, checkout, My Account and order confirmation pages', 'reactive-chat' ), __( 'These pages contain names and addresses. The chat still works there; it only does not read the page.', 'reactive-chat' ) );
		echo '</table>';
		echo '<p class="description">' . esc_html__( 'A suggested text for your privacy policy is available under Settings > Privacy > Policy guide.', 'reactive-chat' ) . '</p>';
		echo '</section>';

		// --- Identity -----------------------------------------------------
		echo '<section class="rc-card" id="rc-identity"><h2>' . esc_html__( 'Signed-in customers', 'reactive-chat' ) . '</h2>';
		echo '<p>' . esc_html__( 'With the identity secret the plugin signs the customer ID of signed-in customers on every page view (valid for one hour). reactive.chat then knows the visitor is that customer. The secret itself never appears in the page.', 'reactive-chat' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::secret_row(
			Reactive_Chat_Options::SECRET_IDENT,
			__( 'Identity secret', 'reactive-chat' ),
			__( 'In your reactive.chat account under Project > Identity. It is shown only once when you create it.', 'reactive-chat' )
		);
		echo '</table></section>';

		// --- Order data ---------------------------------------------------
		echo '<section class="rc-card" id="rc-orders"><h2>' . esc_html__( 'Order data in the chat', 'reactive-chat' ) . '</h2>';
		echo '<p>' . esc_html__( 'The assistant only ever sees the orders of the signed-in customer who is asking. An order number typed into the chat is only a filter within that customer\'s own orders. Requires a paid plan at reactive.chat.', 'reactive-chat' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::select_row( 'mode', __( 'Mode', 'reactive-chat' ), self::mode_labels(), __( 'Choose the same mode in your reactive.chat project under Customer data.', 'reactive-chat' ) );

		self::row_start( 'rc-endpoint', __( 'Endpoint address', 'reactive-chat' ), 'rc-pull-only' );
		echo '<div class="rc-copy"><input type="text" class="large-text code" id="rc-endpoint" value="' . esc_attr( $endpoint ) . '" readonly aria-describedby="rc-endpoint-help" />';
		echo '<button type="button" class="button rc-copy-button" data-rc-copy="rc-endpoint">' . esc_html__( 'Copy', 'reactive-chat' ) . '</button></div>';
		self::help( __( 'Copy this address into your reactive.chat project. reactive.chat only calls https addresses on port 443 and does not follow redirects.', 'reactive-chat' ), 'rc-endpoint-help' );
		if ( 0 !== strpos( $endpoint, 'https://' ) ) {
			echo '<p class="rc-error">' . esc_html__( 'This address is not https - reactive.chat will not call it.', 'reactive-chat' ) . '</p>';
		}
		echo '</td></tr>';
		self::secret_row(
			Reactive_Chat_Options::SECRET_ENDPOINT,
			__( 'Endpoint secret', 'reactive-chat' ),
			__( 'Shown once in your reactive.chat project when you create it (starts with rckd_). Every request from reactive.chat is signed with it.', 'reactive-chat' ),
			'rc-pull-only'
		);
		self::secret_row(
			Reactive_Chat_Options::SECRET_API_KEY,
			__( 'API key', 'reactive-chat' ),
			__( 'Only for Import and Both: an API key with the permission kundendaten:schreiben (Account > API keys).', 'reactive-chat' ),
			'rc-push-only'
		);
		self::text_row( 'project_id', __( 'Project ID', 'reactive-chat' ), __( 'Only needed for Import if your workspace has more than one project.', 'reactive-chat' ), 'text', '', 'rc-push-only' );
		echo '</table>';

		echo '<h3>' . esc_html__( 'Fields that may leave this shop', 'reactive-chat' ) . '</h3>';
		echo '<p>' . esc_html__( 'What is switched off here is never sent - in addition to the selection in your reactive.chat project. Only fields switched on in both places reach the assistant.', 'reactive-chat' ) . '</p>';
		echo '<fieldset class="rc-fields"><legend class="screen-reader-text">' . esc_html__( 'Fields that may leave this shop', 'reactive-chat' ) . '</legend>';
		foreach ( self::field_labels() as $path => $label ) {
			$required = in_array( $path, Reactive_Chat_Schema::REQUIRED, true );
			echo '<label><input type="checkbox" name="fields[]" value="' . esc_attr( $path ) . '"' . checked( in_array( $path, $fields, true ), true, false ) . disabled( $required, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
			if ( $required ) {
				echo '<input type="hidden" name="fields[]" value="' . esc_attr( $path ) . '" />';
			}
		}
		echo '</fieldset>';
		echo '<details class="rc-never"><summary>' . esc_html__( 'Never transmitted (cannot be switched on)', 'reactive-chat' ) . '</summary><ul>';
		foreach ( self::blocklist_labels() as $label ) {
			echo '<li>' . esc_html( $label ) . '</li>';
		}
		echo '<li>' . esc_html__( 'Payment method, transaction IDs, customer notes and line item meta (e.g. engraving texts)', 'reactive-chat' ) . '</li>';
		echo '</ul></details>';
		echo '</section>';

		// --- Advanced -----------------------------------------------------
		echo '<section class="rc-card" id="rc-advanced"><h2>' . esc_html__( 'Advanced', 'reactive-chat' ) . '</h2>';
		echo '<p>' . esc_html__( 'Only change these addresses if reactive.chat tells you to, e.g. when the chat runs through your own domain.', 'reactive-chat' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		/* translators: %s: default address */
		self::text_row( 'cdn_base', __( 'Widget address (CDN)', 'reactive-chat' ), sprintf( __( 'Default: %s', 'reactive-chat' ), Reactive_Chat_Options::DEFAULT_CDN_BASE ), 'url', Reactive_Chat_Options::DEFAULT_CDN_BASE );
		self::text_row( 'ws_url', __( 'WebSocket address', 'reactive-chat' ), __( 'Optional, wss://... - leave empty unless the snippet in your account contains data-rc-ws-url.', 'reactive-chat' ), 'text', 'wss://ws.reactive.chat/rc_wss' );
		/* translators: %s: default address */
		self::text_row( 'base_url', __( 'reactive.chat address', 'reactive-chat' ), sprintf( __( 'For the import API. Default: %s', 'reactive-chat' ), Reactive_Chat_Options::DEFAULT_BASE_URL ), 'url', Reactive_Chat_Options::DEFAULT_BASE_URL );
		echo '</table></section>';

		echo '<div class="rc-save">';
		submit_button( __( 'Save changes', 'reactive-chat' ), 'primary', 'submit', false );
		echo '</div></form>';

		// --- Import -------------------------------------------------------
		if ( in_array( $mode, array( 'import', 'beide' ), true ) ) {
			echo '<section class="rc-card" id="rc-import"><h2>' . esc_html__( 'Import', 'reactive-chat' ) . '</h2>';
			echo '<p>' . esc_html__( 'New and changed orders are transferred automatically in the background. This button transfers all customers with orders from the last 90 days once.', 'reactive-chat' ) . '</p>';
			self::action_button( self::ACTION_BULK, __( 'Transfer all customers now', 'reactive-chat' ), Reactive_Chat_Options::push_enabled() );
			echo '</section>';
		}

		echo '</div>';

		// --- Side column --------------------------------------------------
		echo '<aside class="rc-side">';
		echo '<div class="rc-card"><h2>' . esc_html__( 'Getting started', 'reactive-chat' ) . '</h2><ol class="rc-steps">';
		$steps = array(
			array( $configured, __( 'Create a project for this shop in your reactive.chat account and copy its site key.', 'reactive-chat' ) ),
			array( $configured, __( 'Paste the site key here and save - the chat appears in your shop.', 'reactive-chat' ) ),
			array( '' !== Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_IDENT ), __( 'Optional: create an identity secret so the chat recognises signed-in customers.', 'reactive-chat' ) ),
			array( 'aus' !== $mode, __( 'Optional: switch on order data, copy the endpoint address to reactive.chat and paste the endpoint secret here.', 'reactive-chat' ) ),
		);
		foreach ( $steps as $step ) {
			echo '<li class="' . ( $step[0] ? 'rc-done' : '' ) . '">' . ( $step[0] ? '<span class="screen-reader-text">' . esc_html__( 'Done:', 'reactive-chat' ) . ' </span>' : '' ) . esc_html( $step[1] ) . '</li>';
		}
		echo '</ol>';
		echo '<p><a class="button button-secondary" href="' . esc_url( Reactive_Chat_Options::DEFAULT_BASE_URL . '/app' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open reactive.chat account', 'reactive-chat' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'reactive-chat' ) . '</span></a></p>';
		echo '</div>';
		echo '<div class="rc-card"><h2>' . esc_html__( 'Help', 'reactive-chat' ) . '</h2><ul class="rc-links">';
		$links = array(
			'/en/p/docs'                      => __( 'Documentation', 'reactive-chat' ),
			'/en/p/integration-script-tag'    => __( 'Widget options (script tag reference)', 'reactive-chat' ),
			'/en/p/privacy-policy'            => __( 'Privacy policy of reactive.chat', 'reactive-chat' ),
			'/en/p/data-processing-agreement' => __( 'Data processing agreement', 'reactive-chat' ),
		);
		foreach ( $links as $path => $text ) {
			$url = Reactive_Chat_Options::DEFAULT_BASE_URL . $path;
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'reactive-chat' ) . '</span></a></li>';
		}
		echo '</ul></div>';
		echo '</aside></div></div>';
	}
}

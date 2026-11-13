<?php
/**
 * Plugin Name:          reactive.chat for WooCommerce
 * Plugin URI:           https://reactive.chat/en/p/docs
 * Description:          Live chat with an AI assistant for WooCommerce. Embeds the reactive.chat widget, signs the identity of logged-in customers and lets the assistant answer order questions - only ever for the signed-in customer.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               LW IT Solutions
 * Author URI:           https://reactive.chat
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          reactive-chat
 * WC requires at least: 9.0
 * WC tested up to:      11.1
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/*
 * Overview. One class per concern under includes/:
 *
 *   Options      Settings and secrets (secrets with autoload = no)
 *   Schema       Field catalogue, field selection, block list (pure PHP)
 *   Signature    HMAC for userHash and for the customer data endpoint (pure PHP)
 *   Nonce        Replay protection for the customer data endpoint
 *   Orders       WooCommerce orders of ONE customer -> fixed schema
 *   Rest         POST /wp-json/reactive-chat/v1/kundendaten
 *   Import       PUT/DELETE to reactive.chat via the Action Scheduler
 *   Frontend     Widget loader, consent gate and identify() in the footer
 *   Diagnostics  Status checks, endpoint self-test, API key check
 *   Admin        WooCommerce -> reactive.chat
 */

define( 'REACTIVE_CHAT_VERSION', '1.0.0' );
define( 'REACTIVE_CHAT_FILE', __FILE__ );
define( 'REACTIVE_CHAT_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-reactive-chat-schema.php';
require_once __DIR__ . '/includes/class-reactive-chat-signature.php';
require_once __DIR__ . '/includes/class-reactive-chat-options.php';
require_once __DIR__ . '/includes/class-reactive-chat-nonce.php';
require_once __DIR__ . '/includes/class-reactive-chat-orders.php';
require_once __DIR__ . '/includes/class-reactive-chat-rest.php';
require_once __DIR__ . '/includes/class-reactive-chat-import.php';
require_once __DIR__ . '/includes/class-reactive-chat-frontend.php';
require_once __DIR__ . '/includes/class-reactive-chat-diagnostics.php';
require_once __DIR__ . '/includes/class-reactive-chat-admin.php';

/*
 * HPOS and the block checkout: orders are only read through wc_get_orders() /
 * WC_Order and their meta methods, never through wp_posts/wp_postmeta. The
 * plugin adds nothing to the cart or checkout, so the Cart and Checkout blocks
 * are unaffected.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', REACTIVE_CHAT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', REACTIVE_CHAT_FILE, true );
		}
	}
);

Reactive_Chat_Rest::init();
Reactive_Chat_Import::init();
Reactive_Chat_Frontend::init();
Reactive_Chat_Diagnostics::init();
Reactive_Chat_Admin::init();

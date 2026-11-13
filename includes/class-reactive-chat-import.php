<?php
/**
 * Import: push the orders of a customer to reactive.chat.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Modes "import" and "both": on every change of an order, the newest orders
 * of its customer go to reactive.chat:
 *
 *   PUT    {base}/v1/kundendaten/{user_id}[?project_id=N]  {"bestellungen":[...]}
 *   DELETE {base}/v1/kundendaten/{user_id}[?project_id=N]
 *
 * API key with the scope kundendaten:schreiben, sent as "Authorization:
 * Bearer" AND as X-Api-Key (same value; some environments in front of the API
 * use the Authorization header for HTTP Basic Auth).
 *
 * ASYNCHRONOUS through the Action Scheduler of WooCommerce: the checkout never
 * waits for reactive.chat. At most ONE pending job per customer; it reads the
 * orders only when it runs, so it sends the state of that moment.
 *
 * RETRY on network errors, 429 and 5xx: after 1, 5, 30 minutes, 2 and 12
 * hours, then it gives up with an entry in the WooCommerce log. Other 4xx
 * (wrong key, no paid plan, import switched off in the project) are not
 * retried - retrying does not make them better.
 *
 * Deleted customer: DELETE, even if the import has been switched off since
 * (reactive.chat always accepts DELETE). A pending PUT for a deleted customer
 * sends nothing and triggers the DELETE instead.
 */
final class Reactive_Chat_Import {

	const HOOK_PUT    = 'reactive_chat_import_put';
	const HOOK_DELETE = 'reactive_chat_import_delete';
	const HOOK_BULK   = 'reactive_chat_import_bulk';
	const GROUP       = 'reactive-chat';

	/** Delays of the retries, seconds. */
	const RETRY = array( 60, 300, 1800, 7200, 43200 );

	/** Bulk transfer: orders changed within the last this many days. */
	const BULK_DAYS = 90;

	/** Orders per page of the bulk transfer. */
	const BULK_PAGE = 100;

	/** Set hooks. */
	public static function init() {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order' ), 20, 2 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'on_before_save' ), 20, 1 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_order_removed' ), 20, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'on_order_removed' ), 20, 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'on_before_delete' ), 20, 2 );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ), 10, 1 );
		add_action( self::HOOK_PUT, array( __CLASS__, 'run_put' ), 10, 2 );
		add_action( self::HOOK_DELETE, array( __CLASS__, 'run_delete' ), 10, 2 );
		add_action( self::HOOK_BULK, array( __CLASS__, 'run_bulk' ), 10, 1 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	// ---------------------------------------------------------------------
	// Triggers
	// ---------------------------------------------------------------------

	/**
	 * New or changed order.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order, if passed.
	 */
	public static function on_order( $order_id, $order = null ) {
		if ( ! Reactive_Chat_Options::push_enabled() ) {
			return;
		}
		if ( ! ( $order instanceof WC_Order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		// Real orders only - no refunds (WC_Order_Refund).
		if ( $order instanceof WC_Order ) {
			self::queue_user( (int) $order->get_customer_id() );
		}
	}

	/**
	 * Status change.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public static function on_status_changed( $order_id, $from = '', $to = '', $order = null ) {
		self::on_order( $order_id, $order );
	}

	/**
	 * Order moved to or restored from the trash: the customer's list changes.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_removed( $order_id ) {
		self::on_order( $order_id, null );
	}

	/**
	 * Order about to be deleted permanently. The job reads the orders only when
	 * it runs - after the deletion - so the customer's list is sent without it.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_before_delete( $order_id, $order = null ) {
		self::on_order( $order_id, $order );
	}

	/**
	 * When an order changes its customer, the OLD customer has to be sent as
	 * well - otherwise the order would stay in their list at reactive.chat.
	 * get_data() still carries the old value before saving.
	 *
	 * @param WC_Order $order Order before saving.
	 */
	public static function on_before_save( $order ) {
		if ( ! ( $order instanceof WC_Order ) || ! Reactive_Chat_Options::push_enabled() ) {
			return;
		}
		$changes = $order->get_changes();
		if ( ! is_array( $changes ) || ! array_key_exists( 'customer_id', $changes ) ) {
			return;
		}
		$data = $order->get_data();
		$old  = isset( $data['customer_id'] ) ? (int) $data['customer_id'] : 0;
		if ( $old > 0 && (int) $changes['customer_id'] !== $old ) {
			self::queue_user( $old );
		}
	}

	/**
	 * A user is deleted.
	 *
	 * @param int $user_id User.
	 */
	public static function on_delete_user( $user_id ) {
		self::queue_delete( (int) $user_id );
	}

	// ---------------------------------------------------------------------
	// Jobs
	// ---------------------------------------------------------------------

	/**
	 * Queues a PUT for a customer - at most one is pending.
	 *
	 * @param int $user_id Customer.
	 */
	public static function queue_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! Reactive_Chat_Options::push_enabled() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$args = array( $user_id, 0 );
		if ( self::pending( self::HOOK_PUT, $args ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK_PUT, $args, self::GROUP );
	}

	/**
	 * Queues a DELETE for a customer. Needs only key and base address, not the
	 * mode.
	 *
	 * @param int $user_id Customer.
	 */
	public static function queue_delete( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! self::can_delete() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$args = array( $user_id, 0 );
		if ( self::pending( self::HOOK_DELETE, $args ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK_DELETE, $args, self::GROUP );
	}

	/**
	 * Starts the transfer of all customers with orders of the last BULK_DAYS
	 * days (button on the settings page).
	 *
	 * @return bool queued?
	 */
	public static function queue_bulk() {
		if ( ! Reactive_Chat_Options::push_enabled() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		if ( ! self::pending( self::HOOK_BULK, array( 1 ) ) ) {
			as_enqueue_async_action( self::HOOK_BULK, array( 1 ), self::GROUP );
		}
		return true;
	}

	/**
	 * Is a job with exactly these arguments pending? Only 'pending': a RUNNING
	 * job may already have read the orders, a change after that needs a new one.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Arguments.
	 * @return bool
	 */
	private static function pending( $hook, array $args ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		return is_array( $ids ) && array() !== $ids;
	}

	// ---------------------------------------------------------------------
	// Runs (Action Scheduler)
	// ---------------------------------------------------------------------

	/**
	 * PUT: the newest orders of the customer in the schema.
	 *
	 * @param int $user_id Customer.
	 * @param int $attempt Attempt (0 = first).
	 */
	public static function run_put( $user_id, $attempt = 0 ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! Reactive_Chat_Options::push_enabled() ) {
			return;
		}
		// Customer deleted in the meantime: send nothing, delete instead.
		if ( ! get_userdata( $user_id ) ) {
			self::queue_delete( $user_id );
			return;
		}
		$orders = Reactive_Chat_Orders::for_customer( $user_id, '', Reactive_Chat_Options::fields() );
		$body   = wp_json_encode( array( 'bestellungen' => $orders ) );
		self::send( 'PUT', self::HOOK_PUT, $user_id, (int) $attempt, false === $body ? '{"bestellungen":[]}' : $body );
	}

	/**
	 * DELETE.
	 *
	 * @param int $user_id Customer.
	 * @param int $attempt Attempt.
	 */
	public static function run_delete( $user_id, $attempt = 0 ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! self::can_delete() ) {
			return;
		}
		self::send( 'DELETE', self::HOOK_DELETE, $user_id, (int) $attempt, null );
	}

	/**
	 * Bulk: one page of orders -> queue their customers, then the next page as
	 * a job of its own (no long run).
	 *
	 * @param int $page Page from 1.
	 */
	public static function run_bulk( $page = 1 ) {
		$page = max( 1, (int) $page );
		if ( ! Reactive_Chat_Options::push_enabled() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'limit'         => self::BULK_PAGE,
				'paged'         => $page,
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'date_modified' => '>' . ( time() - self::BULK_DAYS * DAY_IN_SECONDS ),
				'return'        => 'objects',
			)
		);
		if ( ! is_array( $orders ) ) {
			return;
		}
		$seen = array();
		foreach ( $orders as $order ) {
			if ( is_object( $order ) && method_exists( $order, 'get_customer_id' ) ) {
				$customer = (int) $order->get_customer_id();
				if ( $customer > 0 && ! isset( $seen[ $customer ] ) ) {
					$seen[ $customer ] = true;
					self::queue_user( $customer );
				}
			}
		}
		if ( count( $orders ) >= self::BULK_PAGE && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_BULK, array( $page + 1 ), self::GROUP );
		}
	}

	// ---------------------------------------------------------------------
	// HTTP
	// ---------------------------------------------------------------------

	/**
	 * The target address.
	 *
	 * @param int $user_id Customer.
	 * @return string
	 */
	public static function endpoint( $user_id ) {
		$url     = rtrim( (string) Reactive_Chat_Options::get( 'base_url' ), '/' ) . '/v1/kundendaten/' . rawurlencode( (string) $user_id );
		$project = (string) Reactive_Chat_Options::get( 'project_id' );
		if ( '' !== $project ) {
			$url .= '?project_id=' . rawurlencode( $project );
		}
		return $url;
	}

	/**
	 * Headers for the reactive.chat API.
	 *
	 * @param string $key API key.
	 * @return array
	 */
	public static function api_headers( $key ) {
		return array(
			'Authorization' => 'Bearer ' . $key,
			'X-Api-Key'     => $key,
			'Accept'        => 'application/json',
			'User-Agent'    => 'reactive-chat-woocommerce/' . ( defined( 'REACTIVE_CHAT_VERSION' ) ? REACTIVE_CHAT_VERSION : '1' ),
		);
	}

	/**
	 * The fixed error code of a reactive.chat API answer, never the body.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	public static function error_code( $body ) {
		$error   = '';
		$decoded = json_decode( (string) $body, true );
		if ( is_array( $decoded ) && isset( $decoded['error']['code'] ) && is_string( $decoded['error']['code'] ) ) {
			$error = $decoded['error']['code'];
		} elseif ( is_array( $decoded ) && isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			$error = $decoded['error'];
		}
		return substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $error ) ), 0, 40 );
	}

	/**
	 * Sends the request, records the result, schedules a retry if needed.
	 *
	 * @param string      $method  PUT|DELETE.
	 * @param string      $hook    Hook for the retry.
	 * @param int         $user_id Customer.
	 * @param int         $attempt Attempt.
	 * @param string|null $body    JSON or null.
	 * @return int HTTP status (0 = network error).
	 */
	private static function send( $method, $hook, $user_id, $attempt, $body ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => self::api_headers( Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_API_KEY ) ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = $body;
		}
		$response = wp_remote_request( self::endpoint( $user_id ), $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$error    = '';
		if ( is_wp_error( $response ) ) {
			$error = substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $response->get_error_code() ) ), 0, 40 );
		} elseif ( $code < 200 || $code > 299 ) {
			$error = self::error_code( wp_remote_retrieve_body( $response ) );
		}

		$retry = 0 === $code || 429 === $code || $code >= 500;
		$ok    = $code >= 200 && $code <= 299;
		if ( ! $ok && $retry && $attempt < count( self::RETRY ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::RETRY[ $attempt ], $hook, array( $user_id, $attempt + 1 ), self::GROUP );
		} elseif ( ! $ok && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf( 'reactive.chat %s failed: HTTP %d %s (attempt %d)', $method, $code, $error, $attempt + 1 ),
				array( 'source' => 'reactive-chat' )
			);
		}

		// For the settings page: time, method, status, error code - no customer
		// ID, no data.
		update_option(
			Reactive_Chat_Options::IMPORT_STATUS,
			array(
				'time'   => time(),
				'method' => $method,
				'code'   => $code,
				'error'  => $error,
				'retry'  => ! $ok && $retry && $attempt < count( self::RETRY ),
			),
			false
		);
		return $code;
	}

	/**
	 * May data be deleted? Key and base address are enough.
	 *
	 * @return bool
	 */
	private static function can_delete() {
		return '' !== Reactive_Chat_Options::secret( Reactive_Chat_Options::SECRET_API_KEY )
			&& '' !== (string) Reactive_Chat_Options::get( 'base_url' );
	}

	// ---------------------------------------------------------------------
	// Privacy tool of WordPress (Tools -> Erase Personal Data)
	// ---------------------------------------------------------------------

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['reactive-chat'] = array(
			'eraser_friendly_name' => __( 'reactive.chat imported order data', 'reactive-chat' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Queues the deletion of the imported order data at reactive.chat.
	 *
	 * @param string $email E-mail of the request.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$user     = get_user_by( 'email', $email );
		$messages = array();
		if ( $user && self::can_delete() ) {
			self::queue_delete( (int) $user->ID );
			$messages[] = __( 'Deletion of the imported order data at reactive.chat has been queued.', 'reactive-chat' );
		}
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

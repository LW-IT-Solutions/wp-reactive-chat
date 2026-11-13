<?php
/**
 * WooCommerce orders of ONE customer -> raw form -> schema.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads orders through wc_get_orders() (HPOS compatible) and maps them to the
 * raw form that Reactive_Chat_Schema filters.
 *
 * TWO BARRIERS AGAINST FOREIGN ORDERS
 *   1. wc_get_orders( customer_id = user_id ) - the query itself.
 *   2. Every returned order is checked again against get_customer_id(). A
 *      filter of another plugin that bends the query
 *      (woocommerce_order_query_args etc.) still cannot leak foreign orders.
 * The reference from the chat is ONLY a filter within these orders.
 * user_id 0 (guest orders!) never returns anything: customer_id = 0 would be
 * ALL guest orders of the shop.
 *
 * WHAT THE RAW FORM NEVER CONTAINS
 *   Name, address (apart from city/postcode, which the schema only lets
 *   through on request), e-mail, phone, payment method, transaction ID,
 *   customer note, line item meta. Only the getters below are read, plus
 *   named meta keys of tracking and invoice plugins - and of those only
 *   single, named fields.
 */
final class Reactive_Chat_Orders {

	/** This many newest orders are searched when a reference filters. */
	const SEARCH_LIMIT = 100;

	/**
	 * The orders of a customer in the schema, newest first.
	 *
	 * @param int      $user_id   WordPress user ID (> 0).
	 * @param string   $reference Normalized reference ('' = none).
	 * @param string[] $fields    Effective paths.
	 * @param int      $limit     Maximum count.
	 * @return array[]
	 */
	public static function for_customer( $user_id, $reference, array $fields, $limit = Reactive_Chat_Schema::MAX_ORDERS ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'type'        => 'shop_order',
				'limit'       => '' === $reference ? $limit : self::SEARCH_LIMIT,
				// 'date ID': with the same second the higher ID first. 'date' alone
				// left the order open - measured in WooCommerce 11.1 with HPOS.
				'orderby'     => 'date ID',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);
		if ( ! is_array( $orders ) ) {
			return array();
		}

		$out = array();
		foreach ( $orders as $order ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			// Barrier 2: only orders that REALLY belong to this customer.
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) || (int) $order->get_customer_id() !== $user_id ) {
				continue;
			}
			$mapped = Reactive_Chat_Schema::filter_order( self::raw( $order ), $fields );
			if ( null === $mapped ) {
				continue;
			}
			// The reference filters the FILTERED order - a switched-off invoice
			// number finds nothing.
			if ( '' !== $reference && ! Reactive_Chat_Schema::matches_reference( $mapped, $reference ) ) {
				continue;
			}
			$out[] = $mapped;
		}
		return Reactive_Chat_Schema::fit( $out );
	}

	/**
	 * One order -> raw form. Named getters only.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function raw( $order ) {
		$raw = array(
			'nummer'   => (string) $order->get_order_number(),
			'datum'    => '',
			'status'   => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $order->get_status() ) : (string) $order->get_status(),
			'summe'    => self::amount( $order ),
			'waehrung' => (string) $order->get_currency(),
		);
		$created = $order->get_date_created();
		if ( $created && method_exists( $created, 'date' ) ) {
			// Date in the shop's time zone, ISO.
			$raw['datum'] = (string) $created->date( 'Y-m-d' );
		}

		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( count( $items ) >= Reactive_Chat_Schema::MAX_ITEMS ) {
				break;
			}
			// ONLY name and quantity. No line item meta: plugins store engraving
			// texts, gift card recipients and the like there.
			$items[] = array(
				'name'  => (string) $item->get_name(),
				'menge' => $item->get_quantity(),
			);
		}
		$raw['positionen'] = $items;

		$shipping = self::shipping( $order );
		// City/postcode: the schema only lets them through when versand.ort/plz
		// are switched on (off by default). Shipping address, otherwise the
		// billing address (where goods go without a separate shipping address).
		$city     = (string) $order->get_shipping_city();
		$postcode = (string) $order->get_shipping_postcode();
		if ( '' === $city && '' === $postcode ) {
			$city     = (string) $order->get_billing_city();
			$postcode = (string) $order->get_billing_postcode();
		}
		$shipping['ort'] = $city;
		$shipping['plz'] = $postcode;
		$raw['versand']  = $shipping;
		$raw['rechnung'] = self::invoice( $order );

		/**
		 * Adjusts the raw form of an order (e.g. for a tracking plugin not known
		 * here). Whatever comes back runs through the allow list AFTERWARDS -
		 * unknown keys are dropped.
		 *
		 * @param array    $raw   Raw form.
		 * @param WC_Order $order Order.
		 */
		$filtered = apply_filters( 'reactive_chat_order_raw', $raw, $order );
		return is_array( $filtered ) ? $filtered : $raw;
	}

	/**
	 * Total as text with the shop's decimals ("49.90").
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function amount( $order ) {
		$total = $order->get_total();
		if ( function_exists( 'wc_format_decimal' ) && function_exists( 'wc_get_price_decimals' ) ) {
			return (string) wc_format_decimal( $total, wc_get_price_decimals() );
		}
		return (string) $total;
	}

	/**
	 * Shipping from common tracking plugins. Only carrier, tracking number and
	 * status - never the whole meta array.
	 *
	 * 1. WooCommerce Shipment Tracking and Advanced Shipment Tracking:
	 *    meta _wc_shipment_tracking_items (list; the last one counts).
	 * 2. Germanized (shipments): wc_gzd_get_shipments_by_order(), only if the
	 *    function and methods exist.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function shipping( $order ) {
		$out = array(
			'dienst'         => '',
			'sendungsnummer' => '',
			'status'         => '',
		);

		$items = $order->get_meta( '_wc_shipment_tracking_items', true );
		if ( is_array( $items ) && array() !== $items ) {
			$last = end( $items );
			if ( is_array( $last ) ) {
				$provider = '';
				if ( ! empty( $last['custom_tracking_provider'] ) && is_string( $last['custom_tracking_provider'] ) ) {
					$provider = $last['custom_tracking_provider'];
				} elseif ( ! empty( $last['tracking_provider'] ) && is_string( $last['tracking_provider'] ) ) {
					$provider = self::provider_name( $last['tracking_provider'] );
				}
				$number                = isset( $last['tracking_number'] ) && is_scalar( $last['tracking_number'] ) ? (string) $last['tracking_number'] : '';
				$out['dienst']         = $provider;
				$out['sendungsnummer'] = $number;
				if ( '' !== $number ) {
					$out['status'] = __( 'Shipped', 'reactive-chat' );
				}
				return $out;
			}
		}

		if ( function_exists( 'wc_gzd_get_shipments_by_order' ) ) {
			$shipments = wc_gzd_get_shipments_by_order( $order );
			if ( is_array( $shipments ) && array() !== $shipments ) {
				$shipment = end( $shipments );
				if ( is_object( $shipment ) ) {
					if ( method_exists( $shipment, 'get_tracking_id' ) ) {
						$out['sendungsnummer'] = (string) $shipment->get_tracking_id();
					}
					if ( method_exists( $shipment, 'get_shipping_provider_title' ) ) {
						$out['dienst'] = (string) $shipment->get_shipping_provider_title();
					} elseif ( method_exists( $shipment, 'get_shipping_provider' ) ) {
						$out['dienst'] = (string) $shipment->get_shipping_provider();
					}
					if ( method_exists( $shipment, 'get_status' ) ) {
						$status        = (string) $shipment->get_status();
						$out['status'] = function_exists( 'wc_gzd_get_shipment_status_name' ) ? (string) wc_gzd_get_shipment_status_name( $status ) : $status;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Carrier name from the slug: "dhl" -> "DHL", "deutsche-post-dhl" ->
	 * "Deutsche Post DHL". Short words (up to three characters) and USPS are
	 * abbreviations.
	 *
	 * @param string $slug Slug of the tracking plugin.
	 * @return string
	 */
	private static function provider_name( $slug ) {
		$words = array();
		foreach ( preg_split( '/[-_\s]+/', strtolower( $slug ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$words[] = ( strlen( $word ) <= 3 || 'usps' === $word ) ? strtoupper( $word ) : ucfirst( $word );
		}
		return implode( ' ', $words );
	}

	/**
	 * Invoice: number from common invoice plugins, status from the payment
	 * state of the order, due date empty (WooCommerce has none).
	 *
	 * 1. PDF Invoices & Packing Slips for WooCommerce:
	 *    _wcpdf_invoice_number_data['formatted_number'], else _wcpdf_invoice_number.
	 * 2. Germanized Pro: wc_gzdp_get_order_last_invoice(), only if function and
	 *    method exist.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function invoice( $order ) {
		$number = '';
		$data   = $order->get_meta( '_wcpdf_invoice_number_data', true );
		if ( is_array( $data ) && isset( $data['formatted_number'] ) && is_scalar( $data['formatted_number'] ) ) {
			$number = (string) $data['formatted_number'];
		}
		if ( '' === $number ) {
			$plain = $order->get_meta( '_wcpdf_invoice_number', true );
			if ( is_scalar( $plain ) ) {
				$number = (string) $plain;
			}
		}
		if ( '' === $number && function_exists( 'wc_gzdp_get_order_last_invoice' ) ) {
			$invoice = wc_gzdp_get_order_last_invoice( $order );
			if ( is_object( $invoice ) && method_exists( $invoice, 'get_formatted_number' ) ) {
				$number = (string) $invoice->get_formatted_number();
			}
		}

		$status = '';
		$state  = (string) $order->get_status();
		if ( $order->is_paid() ) {
			$status = __( 'Paid', 'reactive-chat' );
		} elseif ( 'refunded' === $state ) {
			$status = __( 'Refunded', 'reactive-chat' );
		} elseif ( in_array( $state, array( 'pending', 'on-hold' ), true ) ) {
			$status = __( 'Unpaid', 'reactive-chat' );
		}

		return array(
			'nummer'     => $number,
			'status'     => $status,
			'faellig_am' => '',
		);
	}
}

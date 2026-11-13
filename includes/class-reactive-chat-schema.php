<?php
/**
 * The data schema: catalogue, field selection, block list, mapping.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * The fixed schema of an order.
 *
 * The SAME LIST as the field catalogue of the reactive.chat customer data
 * feature. reactive.chat discards everything that is switched off anyway -
 * this plugin discards it BEFORE, so it never leaves the shop.
 *
 * THE ALLOW LIST IS WHAT COUNTS. filter_order() builds the output field by
 * field from the catalogue paths; a key the catalogue does not know cannot
 * slip through, whatever a tracking or invoice plugin writes into its meta.
 * BLOCKLIST is documentation for the settings page, not a second truth.
 *
 * Pure PHP apart from wp_json_encode(), so the class can be tested with a
 * single stub. The German keys (nummer, datum, ...) are the wire format of the
 * reactive.chat API and must not be translated.
 */
final class Reactive_Chat_Schema {

	/** Path => on by default? */
	const CATALOG = array(
		'bestellung.nummer'      => true,
		'bestellung.datum'       => true,
		'bestellung.status'      => true,
		'bestellung.summe'       => true,
		'bestellung.waehrung'    => true,
		'positionen.name'        => true,
		'positionen.menge'       => true,
		'versand.dienst'         => true,
		'versand.sendungsnummer' => true,
		'versand.status'         => true,
		'versand.ort'            => false,
		'versand.plz'            => false,
		'rechnung.nummer'        => true,
		'rechnung.status'        => true,
		'rechnung.faellig_am'    => true,
	);

	/** Cannot be switched off: the key of the storage and of the reference filter. */
	const REQUIRED = array( 'bestellung.nummer' );

	/**
	 * What is NEVER transmitted, not switchable. Key = kind; the translated
	 * label comes from Reactive_Chat_Admin::blocklist_labels().
	 */
	const BLOCKLIST = array( 'anschrift', 'empfaenger', 'email', 'telefon', 'bank', 'karte', 'geburt', 'zugang', 'steuer' );

	/** At most this many orders per answer or import. */
	const MAX_ORDERS = 10;

	/** At most this many line items per order. */
	const MAX_ITEMS = 20;

	/** Strings at most this long (characters). */
	const MAX_TEXT = 200;

	/** The order number at most this long. */
	const MAX_NUMBER = 80;

	/**
	 * Maximum answer size in bytes, MEASURED WITH json_encode WITHOUT FLAGS.
	 * reactive.chat stops reading at 16384 bytes, and the WordPress REST API
	 * writes non-ASCII characters as \u sequences - so the size is measured in
	 * exactly this (longer) form, with room for the envelope.
	 */
	const MAX_BYTES = 15360;

	/**
	 * The EFFECTIVE field list: paths that are on.
	 *
	 * @param array|null $selection List of switched-on paths; null = defaults.
	 * @return string[] Paths in catalogue order.
	 */
	public static function effective_fields( $selection ) {
		$on = array();
		foreach ( self::CATALOG as $path => $default ) {
			$enabled = is_array( $selection ) ? in_array( $path, $selection, true ) : $default;
			if ( $enabled || in_array( $path, self::REQUIRED, true ) ) {
				$on[] = $path;
			}
		}
		// A currency on its own says nothing - only together with the total.
		if ( in_array( 'bestellung.waehrung', $on, true ) && ! in_array( 'bestellung.summe', $on, true ) ) {
			$on = array_values( array_diff( $on, array( 'bestellung.waehrung' ) ) );
		}
		return $on;
	}

	/**
	 * Form input -> a valid selection: only known paths.
	 *
	 * @param mixed $input List from the form.
	 * @return string[]
	 */
	public static function sanitize_selection( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$out = array();
		foreach ( $input as $path ) {
			if ( is_string( $path ) && array_key_exists( $path, self::CATALOG ) ) {
				$out[] = $path;
			}
		}
		return self::effective_fields( $out );
	}

	/**
	 * A raw order -> the schema. Only switched-on catalogue fields.
	 *
	 * @param array    $raw    Raw form from Reactive_Chat_Orders::raw().
	 * @param string[] $fields Effective paths (effective_fields()).
	 * @return array|null null without an order number.
	 */
	public static function filter_order( array $raw, array $fields ) {
		$on  = array_flip( $fields );
		$out = array();

		$number = self::text( isset( $raw['nummer'] ) ? $raw['nummer'] : null, self::MAX_NUMBER );
		if ( null === $number ) {
			return null;
		}
		$out['nummer'] = $number;

		foreach ( array( 'datum', 'status', 'summe', 'waehrung' ) as $field ) {
			if ( isset( $on[ 'bestellung.' . $field ] ) && isset( $raw[ $field ] ) ) {
				$value = self::text( $raw[ $field ], self::MAX_TEXT );
				if ( null !== $value ) {
					$out[ $field ] = $value;
				}
			}
		}
		if ( isset( $out['waehrung'] ) && ! isset( $out['summe'] ) ) {
			unset( $out['waehrung'] );
		}

		if ( ( isset( $on['positionen.name'] ) || isset( $on['positionen.menge'] ) )
			&& isset( $raw['positionen'] ) && is_array( $raw['positionen'] ) ) {
			$items = array();
			foreach ( $raw['positionen'] as $item ) {
				if ( count( $items ) >= self::MAX_ITEMS ) {
					break;
				}
				if ( ! is_array( $item ) ) {
					continue;
				}
				$one = array();
				if ( isset( $on['positionen.name'] ) && isset( $item['name'] ) ) {
					$name = self::text( $item['name'], self::MAX_TEXT );
					if ( null !== $name ) {
						$one['name'] = $name;
					}
				}
				if ( isset( $on['positionen.menge'] ) && isset( $item['menge'] ) ) {
					$qty = self::number( $item['menge'] );
					if ( null !== $qty ) {
						$one['menge'] = $qty;
					}
				}
				if ( array() !== $one ) {
					$items[] = $one;
				}
			}
			if ( array() !== $items ) {
				$out['positionen'] = $items;
			}
		}

		$sub = array(
			'versand'  => array( 'dienst', 'sendungsnummer', 'status', 'ort', 'plz' ),
			'rechnung' => array( 'nummer', 'status', 'faellig_am' ),
		);
		foreach ( $sub as $group => $keys ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) {
				continue;
			}
			$one = array();
			foreach ( $keys as $key ) {
				if ( isset( $on[ $group . '.' . $key ] ) && isset( $raw[ $group ][ $key ] ) ) {
					$value = self::text( $raw[ $group ][ $key ], self::MAX_TEXT );
					if ( null !== $value ) {
						$one[ $key ] = $value;
					}
				}
			}
			if ( array() !== $one ) {
				$out[ $group ] = $one;
			}
		}
		return $out;
	}

	/**
	 * Several raw orders -> list in the schema, at most $max, at most
	 * MAX_BYTES (cut from the end: the order is newest first).
	 *
	 * @param array[]  $raws   Raw orders.
	 * @param string[] $fields Effective paths.
	 * @param int      $max    Maximum count.
	 * @return array[]
	 */
	public static function filter_list( array $raws, array $fields, $max = self::MAX_ORDERS ) {
		$out  = array();
		$seen = array();
		foreach ( $raws as $raw ) {
			if ( count( $out ) >= $max ) {
				break;
			}
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$order = self::filter_order( $raw, $fields );
			if ( null === $order ) {
				continue;
			}
			$key = self::normalize_reference( $order['nummer'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $order;
		}
		return self::fit( $out );
	}

	/**
	 * Cuts from the end until the answer stays below MAX_BYTES.
	 *
	 * @param array[] $orders Orders in the schema.
	 * @return array[]
	 */
	public static function fit( array $orders ) {
		// wp_json_encode() without flags: the same escaped form as the REST API.
		while ( array() !== $orders && strlen( (string) wp_json_encode( array( 'bestellungen' => $orders ) ) ) > self::MAX_BYTES ) {
			array_pop( $orders );
		}
		return $orders;
	}

	/**
	 * Normalizes a reference like reactive.chat does: white space and '#'
	 * removed, lower case.
	 *
	 * @param mixed $reference Number from the chat message.
	 * @return string
	 */
	public static function normalize_reference( $reference ) {
		if ( ! is_string( $reference ) && ! is_int( $reference ) ) {
			return '';
		}
		$value = (string) preg_replace( '/[\s#]+/u', '', (string) $reference );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_NUMBER, 'UTF-8' ) : substr( $value, 0, self::MAX_NUMBER );
	}

	/**
	 * Does the reference match this order? Order, invoice or tracking number.
	 * ONLY a filter within the orders of the one customer.
	 *
	 * @param array  $raw       Order (raw or mapped).
	 * @param string $reference Already normalized.
	 * @return bool
	 */
	public static function matches_reference( array $raw, $reference ) {
		if ( '' === $reference ) {
			return false;
		}
		$candidates = array(
			isset( $raw['nummer'] ) ? $raw['nummer'] : null,
			isset( $raw['rechnung']['nummer'] ) ? $raw['rechnung']['nummer'] : null,
			isset( $raw['versand']['sendungsnummer'] ) ? $raw['versand']['sendungsnummer'] : null,
		);
		foreach ( $candidates as $candidate ) {
			if ( ( is_string( $candidate ) || is_int( $candidate ) ) && self::normalize_reference( $candidate ) === $reference ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A text value: scalars only, control characters removed, shortened;
	 * empty -> null.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum length in characters.
	 * @return string|null
	 */
	public static function text( $value, $max ) {
		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$value = (string) mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		// Control characters and line separators out: a field is one line.
		$value = (string) preg_replace( '/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $value );
		$value = trim( (string) preg_replace( '/\s{2,}/u', ' ', $value ) );
		if ( '' === $value ) {
			return null;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
	}

	/**
	 * A quantity: int or float, otherwise null.
	 *
	 * @param mixed $value Raw value.
	 * @return int|float|null
	 */
	public static function number( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			return floor( $value ) === $value ? (int) $value : $value;
		}
		if ( is_string( $value ) && preg_match( '/^\s*-?\d{1,12}([.,]\d{1,6})?\s*$/', $value ) ) {
			$value = str_replace( ',', '.', trim( $value ) );
			return false !== strpos( $value, '.' ) ? (float) $value : (int) $value;
		}
		return null;
	}
}

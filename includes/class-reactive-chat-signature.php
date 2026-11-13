<?php
/**
 * Signatures: userHash for identify() and the check of the customer data endpoint.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two HMAC-SHA256, both hex, both keyed with the secret AS A STRING (this is
 * how the reactive.chat server computes them):
 *
 *   userHash      = HMAC(IDENTITY_SECRET, userId . '.' . exp)
 *   X-RC-Signatur = HMAC(ENDPOINT_SECRET, X-RC-Zeit . '.' . rawBody)
 *
 * Pure PHP: no WordPress function.
 */
final class Reactive_Chat_Signature {

	/** Time window of a request: +/- this many seconds. */
	const WINDOW = 300;

	/** Lifetime of a userHash in seconds. */
	const IDENT_TTL = 3600;

	/** Upper limit for exp accepted by reactive.chat (24 hours). */
	const IDENT_TTL_MAX = 86400;

	/**
	 * The userHash for identify().
	 *
	 * @param string $secret  Identity secret of the project.
	 * @param string $user_id ID of the customer.
	 * @param int    $exp     Expiry, Unix seconds.
	 * @return string 64 hex characters.
	 */
	public static function ident_hash( $secret, $user_id, $exp ) {
		return hash_hmac( 'sha256', $user_id . '.' . $exp, $secret );
	}

	/**
	 * The userHash WITH e-mail: HMAC(secret, userId . '.' . exp . '.' . email).
	 * Only then does reactive.chat treat the address as confirmed (booking,
	 * integrations that look up the e-mail) - an address outside the signature
	 * could be set freely by any logged-in customer.
	 *
	 * @param string $secret  Identity secret of the project.
	 * @param string $user_id ID of the customer.
	 * @param int    $exp     Expiry, Unix seconds.
	 * @param string $email   The address exactly as passed to identify().
	 * @return string 64 hex characters.
	 */
	public static function ident_hash_email( $secret, $user_id, $exp, $email ) {
		return hash_hmac( 'sha256', $user_id . '.' . $exp . '.' . $email, $secret );
	}

	/**
	 * The signature reactive.chat sends.
	 *
	 * @param string $secret Endpoint secret.
	 * @param string $time   X-RC-Zeit (Unix seconds as text).
	 * @param string $body   Raw request body, byte for byte.
	 * @return string 64 hex characters.
	 */
	public static function sign( $secret, $time, $body ) {
		return hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}

	/**
	 * Checks time and signature of a request. Empty string = valid, otherwise
	 * a fixed reason (never content).
	 *
	 * The comparison is constant-time (hash_equals). The format checks before
	 * it only reveal what is public anyway (length, hex).
	 *
	 * @param string $secret    Endpoint secret.
	 * @param mixed  $time      Header X-RC-Zeit.
	 * @param mixed  $signature Header X-RC-Signatur.
	 * @param string $body      Raw body.
	 * @param int    $now       Now, Unix seconds.
	 * @return string '' or no_secret|bad_time|stale|bad_signature
	 */
	public static function check( $secret, $time, $signature, $body, $now ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			return 'no_secret';
		}
		$time = is_string( $time ) ? trim( $time ) : '';
		if ( ! preg_match( '/^\d{1,12}$/', $time ) ) {
			return 'bad_time';
		}
		if ( abs( (int) $now - (int) $time ) > self::WINDOW ) {
			return 'stale';
		}
		$signature = is_string( $signature ) ? strtolower( trim( $signature ) ) : '';
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return 'bad_signature';
		}
		if ( ! hash_equals( self::sign( $secret, $time, (string) $body ), $signature ) ) {
			return 'bad_signature';
		}
		return '';
	}
}

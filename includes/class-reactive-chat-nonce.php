<?php
/**
 * Replay protection for the customer data endpoint.
 *
 * @package ReactiveChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every nonce is accepted exactly once within TTL seconds.
 *
 * The signature window (+/- 300 s) already limits HOW LONG a captured request
 * is usable; the nonce turns that into "exactly once". TTL is longer than the
 * whole window (2 x 300 s), so a request at the edge cannot pass again after
 * the lock has expired.
 *
 * With a persistent object cache (Redis, Memcached) wp_cache_add() is atomic.
 * Without one, the seen nonces live in ONE option (autoload = no) that is
 * pruned on every call and removed on uninstall. Between reading and writing
 * there is a window of milliseconds in which two SIMULTANEOUS replays could
 * both pass - both with the same answer for the same customer, so without
 * gain for an attacker who does not have the endpoint secret.
 */
final class Reactive_Chat_Nonce {

	const TTL    = 600;
	const OPTION = 'reactive_chat_nonces';
	const GROUP  = 'reactive-chat';

	/** Upper bound for the stored list; the oldest entries go first. */
	const MAX_ENTRIES = 2000;

	/**
	 * Does the nonce have the expected form? reactive.chat sends 32 hex
	 * characters; a little more room is allowed.
	 *
	 * @param mixed $nonce Value from the body.
	 * @return bool
	 */
	public static function valid( $nonce ) {
		return is_string( $nonce ) && 1 === preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $nonce );
	}

	/**
	 * Claims the nonce. true = first time, false = seen before or invalid.
	 *
	 * @param mixed $nonce Value from the body.
	 * @return bool
	 */
	public static function claim( $nonce ) {
		if ( ! self::valid( $nonce ) ) {
			return false;
		}
		$key = substr( hash( 'sha256', $nonce ), 0, 40 );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( 'nonce_' . $key, 1, self::GROUP, self::TTL );
		}

		$now  = time();
		$seen = get_option( self::OPTION, array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		// Drop expired entries.
		foreach ( $seen as $hash => $until ) {
			if ( ! is_int( $until ) || $until < $now ) {
				unset( $seen[ $hash ] );
			}
		}
		if ( isset( $seen[ $key ] ) ) {
			return false;
		}
		$seen[ $key ] = $now + self::TTL;
		if ( count( $seen ) > self::MAX_ENTRIES ) {
			asort( $seen );
			$seen = array_slice( $seen, -self::MAX_ENTRIES, null, true );
		}
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $seen, '', 'no' );
		} else {
			update_option( self::OPTION, $seen, false );
		}
		return true;
	}
}

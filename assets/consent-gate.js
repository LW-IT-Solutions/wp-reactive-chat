/**
 * reactive.chat for WooCommerce - consent gate.
 *
 * Loads the reactive.chat widget loader only after the visitor has consented.
 * Until then nothing is requested from reactive.chat.
 *
 * Settings come from window.reactiveChatGate (printed by the plugin):
 *   src       address of loader.js
 *   attrs     data-rc-* attributes for the loader tag
 *   source    'wp_consent_api' | 'tcf' | 'event'
 *   category  WP Consent API category (source 'wp_consent_api')
 *
 * Every source also accepts the JavaScript signal of the site:
 *   window.reactiveChatConsent( true )   or   ( false ) to withdraw
 *   document.dispatchEvent( new CustomEvent( 'reactive-chat:consent', { detail: true } ) )
 */
( function ( w, d ) {
	'use strict';

	var c = w.reactiveChatGate;
	if ( ! c || typeof c.src !== 'string' || ! /^https:\/\//.test( c.src ) ) {
		return;
	}

	var state = 'waiting'; // waiting | loaded | stopped

	function load() {
		// After a withdrawal the widget stays off on this page; renewed consent
		// takes effect with the next page view.
		if ( state !== 'waiting' ) {
			return;
		}
		state = 'loaded';
		if ( d.getElementById( 'reactive-chat-loader' ) ) {
			return;
		}
		var s = d.createElement( 'script' );
		var attrs = c.attrs || {};
		s.src = c.src;
		s.async = true;
		s.id = 'reactive-chat-loader';
		for ( var name in attrs ) {
			if ( Object.prototype.hasOwnProperty.call( attrs, name ) && /^data-rc-[a-z][a-z0-9-]*$/.test( name ) ) {
				s.setAttribute( name, String( attrs[ name ] ) );
			}
		}
		// The visitor has consented through this gate.
		s.setAttribute( 'data-rc-consent', 'granted' );
		( d.body || d.head || d.documentElement ).appendChild( s );
	}

	function withdraw() {
		if ( state !== 'loaded' ) {
			return;
		}
		state = 'stopped';
		if ( typeof w.ReactiveChat === 'function' ) {
			w.ReactiveChat( 'shutdown' );
		}
	}

	function signal( granted ) {
		if ( granted === false ) {
			withdraw();
		} else {
			load();
		}
	}

	// The site's own signal works with every source.
	w.reactiveChatConsent = signal;
	d.addEventListener( 'reactive-chat:consent', function ( e ) {
		signal( e && e.detail === false ? false : true );
	} );

	if ( c.source === 'wp_consent_api' ) {
		var category = typeof c.category === 'string' ? c.category : 'functional';
		var check = function () {
			return typeof w.wp_has_consent === 'function' && w.wp_has_consent( category );
		};
		if ( check() ) {
			load();
		} else {
			// The WP Consent API script may run after this one.
			d.addEventListener( 'DOMContentLoaded', function () {
				if ( check() ) {
					load();
				}
			} );
		}
		d.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
			var changed = e && e.detail;
			if ( ! changed || ! Object.prototype.hasOwnProperty.call( changed, category ) ) {
				return;
			}
			signal( changed[ category ] === 'allow' );
		} );
	} else if ( c.source === 'tcf' ) {
		var tries = 0;
		var listen = function () {
			if ( typeof w.__tcfapi !== 'function' ) {
				if ( tries++ < 50 ) {
					w.setTimeout( listen, 200 );
				}
				return;
			}
			w.__tcfapi( 'addEventListener', 2, function ( data, ok ) {
				if ( ! ok || ! data || ( data.eventStatus !== 'tcloaded' && data.eventStatus !== 'useractioncomplete' ) ) {
					return;
				}
				// Purpose 1: store and/or access information on a device.
				var granted = ! data.gdprApplies || !! ( data.purpose && data.purpose.consents && data.purpose.consents[ 1 ] );
				signal( granted );
			} );
		};
		listen();
	}
}( window, document ) );

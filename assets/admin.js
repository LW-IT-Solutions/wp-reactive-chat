/**
 * reactive.chat for WooCommerce - settings page.
 *
 * Copy button for the endpoint address and showing only the rows that apply
 * to the chosen modes. Without JavaScript every row stays visible.
 */
( function ( d ) {
	'use strict';

	var texts = window.reactiveChatAdmin || { copied: 'Copied', copy: 'Copy' };

	function each( selector, fn ) {
		Array.prototype.forEach.call( d.querySelectorAll( selector ), fn );
	}

	// Copy button.
	each( '.rc-copy-button', function ( button ) {
		button.addEventListener( 'click', function () {
			var field = d.getElementById( button.getAttribute( 'data-rc-copy' ) );
			if ( ! field ) {
				return;
			}
			var done = function () {
				button.textContent = texts.copied;
				window.setTimeout( function () {
					button.textContent = texts.copy;
				}, 2000 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( field.value ).then( done, function () {
					field.select();
				} );
			} else {
				field.select();
				try {
					if ( d.execCommand( 'copy' ) ) {
						done();
					}
				} catch ( e ) {}
			}
		} );
	} );

	// Rows that depend on a mode.
	var mode = d.getElementById( 'rc-mode' );
	var consentRadios = d.querySelectorAll( 'input[name="consent_mode"]' );
	var source = d.getElementById( 'rc-consent-source' );

	function toggle( selector, show ) {
		each( selector, function ( row ) {
			row.classList.toggle( 'rc-hidden', ! show );
		} );
	}

	function update() {
		var m = mode ? mode.value : '';
		toggle( '.rc-pull-only', m === 'abruf' || m === 'beide' );
		toggle( '.rc-push-only', m === 'import' || m === 'beide' );

		var wait = false;
		Array.prototype.forEach.call( consentRadios, function ( radio ) {
			if ( radio.checked && radio.value === 'wait' ) {
				wait = true;
			}
		} );
		toggle( '.rc-wait-only', wait );
		var category = d.getElementById( 'rc-consent-category' );
		if ( category && wait ) {
			var row = category.closest( 'tr' );
			if ( row ) {
				row.classList.toggle( 'rc-hidden', ! source || source.value !== 'wp_consent_api' );
			}
		}
	}

	if ( mode ) {
		mode.addEventListener( 'change', update );
	}
	if ( source ) {
		source.addEventListener( 'change', update );
	}
	Array.prototype.forEach.call( consentRadios, function ( radio ) {
		radio.addEventListener( 'change', update );
	} );
	update();
}( document ) );

/**
 * Paynet saved cards: confirmation window and deletion (classic checkout, "My account", used by blocks.js).
 * ТЗ разд. 9б «Удаление карты».
 */
( function () {
	var t = window.paynetWcCards || {};

	function format( text, value ) {
		return String( text || '' ).replace( '%s', value );
	}

	// Confirmation window: resolves true for "Yes", false for "Cancel".
	function confirmDelete( label ) {
		return new Promise( function ( resolve ) {
			var overlay = document.createElement( 'div' );
			overlay.className = 'paynet-wc-modal';
			overlay.setAttribute( 'role', 'dialog' );
			overlay.setAttribute( 'aria-modal', 'true' );
			var box = document.createElement( 'div' );
			box.className = 'paynet-wc-modal-box';
			var p = document.createElement( 'p' );
			p.textContent = format( t.confirm, label );
			var yes = document.createElement( 'button' );
			yes.type = 'button';
			yes.className = 'button paynet-wc-modal-yes';
			yes.textContent = t.yes || 'Yes';
			var no = document.createElement( 'button' );
			no.type = 'button';
			no.className = 'button paynet-wc-modal-no';
			no.textContent = t.no || 'Cancel';
			box.appendChild( p );
			box.appendChild( yes );
			box.appendChild( no );
			overlay.appendChild( box );
			document.body.appendChild( overlay );
			function done( value ) {
				overlay.remove();
				resolve( value );
			}
			yes.addEventListener( 'click', function () { done( true ); } );
			no.addEventListener( 'click', function () { done( false ); } );
			no.focus();
		} );
	}

	// Server deletes in Paynet first, then in WordPress. Resolves { ok, message }.
	function removeCard( id ) {
		var data = new FormData();
		data.append( 'action', 'paynet_wc_delete_card' );
		data.append( 'nonce', t.nonce );
		data.append( 'token', id );
		return fetch( t.ajax, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) {
				return r.json().catch( function () { return {}; } );
			} )
			.then( function ( json ) {
				return { ok: !! ( json && json.success ), message: ( json && json.data && json.data.message ) || t.failed };
			} )
			.catch( function () {
				return { ok: false, message: t.failed };
			} );
	}

	function showError( anchor, message ) {
		var old = anchor.querySelector( '.paynet-wc-card-error' );
		if ( old ) {
			old.remove();
		}
		var e = document.createElement( 'div' );
		e.className = 'paynet-wc-card-error woocommerce-error';
		e.setAttribute( 'role', 'alert' );
		e.textContent = message;
		anchor.appendChild( e );
	}

	window.paynetWcCardsApi = { confirm: confirmDelete, remove: removeCard };

	document.addEventListener( 'click', function ( event ) {
		// Classic checkout list.
		var btn = event.target.closest && event.target.closest( '.paynet-wc-cards .paynet-wc-card-delete' );
		if ( btn ) {
			event.preventDefault();
			var li = btn.closest( 'li' );
			var list = btn.closest( '.paynet-wc-cards' );
			confirmDelete( btn.getAttribute( 'data-label' ) ).then( function ( yes ) {
				if ( ! yes ) {
					return;
				}
				btn.disabled = true;
				removeCard( btn.getAttribute( 'data-token' ) ).then( function ( res ) {
					btn.disabled = false;
					if ( ! res.ok ) {
						showError( list, res.message );
						return;
					}
					var wasChecked = li.querySelector( 'input' ).checked;
					li.remove();
					var rest = list.querySelectorAll( '.paynet-wc-card:not(.paynet-wc-card-new) input' );
					if ( ! rest.length ) {
						list.remove(); // No cards left: nothing under the payment method.
						return;
					}
					if ( wasChecked ) {
						rest[ 0 ].checked = true;
					}
				} );
			} );
			return;
		}

		// "My account → Payment methods".
		var link = event.target.closest && event.target.closest( 'a[href*="paynet-wc-delete-card="]' );
		if ( link ) {
			event.preventDefault();
			var row = link.closest( 'tr' );
			var id = ( link.getAttribute( 'href' ).match( /paynet-wc-delete-card=(\d+)/ ) || [] )[ 1 ];
			var method = row && row.querySelector( '.payment-method-method' );
			var label = method ? method.textContent.trim() : '';
			confirmDelete( label ).then( function ( yes ) {
				if ( ! yes ) {
					return;
				}
				removeCard( id ).then( function ( res ) {
					if ( ! res.ok ) {
						showError( row ? row.querySelector( '.payment-method-actions' ) || row : document.body, res.message );
						return;
					}
					if ( row ) {
						row.remove();
					}
				} );
			} );
		}
	} );
} )();

( function () {
	function field( form, name ) {
		return form.querySelector( '#woocommerce_paynet_wc_' + name );
	}

	function enableSave( form ) {
		var save = form.querySelector( 'button[name="save"]' );
		if ( save ) {
			save.disabled = false; // WooCommerce keeps Save disabled until a field changes.
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var regen = event.target.closest( '.paynet-wc-regenerate-salt' );
		if ( regen ) {
			var form = regen.closest( 'form' );
			var flag = form && form.querySelector( '.paynet-wc-regenerate-flag' );
			var save = form && form.querySelector( 'button[name="save"]' );
			if ( flag && save ) {
				flag.value = '1';
				save.disabled = false;
				save.click();
			}
			return;
		}

		var fill = event.target.closest( '.paynet-wc-test-fill' );
		if ( fill ) {
			var f = fill.closest( 'form' );
			var creds = JSON.parse( fill.getAttribute( 'data-credentials' ) || '{}' );
			Object.keys( creds ).forEach( function ( key ) {
				var input = field( f, 'test_' + key );
				if ( input ) {
					input.value = creds[ key ];
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
			enableSave( f );
			return;
		}

		var check = event.target.closest( '.paynet-wc-check' );
		if ( check ) {
			var cf = check.closest( 'form' );
			var mode = field( cf, 'mode' ) ? field( cf, 'mode' ).value : 'test';
			var result = cf.querySelector( '.paynet-wc-check-result' );
			var data = new FormData();
			data.append( 'action', 'paynet_wc_check_connection' );
			data.append( 'nonce', check.getAttribute( 'data-nonce' ) );
			data.append( 'mode', mode );
			data.append( 'username', field( cf, mode + '_username' ) ? field( cf, mode + '_username' ).value : '' );
			data.append( 'password', field( cf, mode + '_password' ) ? field( cf, mode + '_password' ).value : '' );
			check.disabled = true;
			result.textContent = '…';
			result.style.color = '';
			fetch( check.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					var ok = json && json.success;
					result.textContent = ( ok ? '✓ ' : '✗ ' ) + ( json && json.data && json.data.message ? json.data.message : '' );
					result.style.color = ok ? '#008a20' : '#d63638';
				} )
				.catch( function ( e ) {
					result.textContent = '✗ ' + e;
					result.style.color = '#d63638';
				} )
				.finally( function () {
					check.disabled = false;
				} );
			return;
		}

		var button = event.target.closest( '.paynet-wc-copy' );
		if ( ! button ) {
			return;
		}
		var input = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! input ) {
			return;
		}
		input.select();
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( input.value );
		} else {
			document.execCommand( 'copy' );
		}
	} );
} )();

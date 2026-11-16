( function () {
	document.addEventListener( 'click', function ( event ) {
		var regen = event.target.closest( '.paynet-wc-regenerate-salt' );
		if ( regen ) {
			var form = regen.closest( 'form' );
			var flag = form && form.querySelector( '.paynet-wc-regenerate-flag' );
			var save = form && form.querySelector( 'button[name="save"]' );
			if ( flag && save ) {
				flag.value = '1';
				save.disabled = false; // WooCommerce keeps Save disabled until a field changes.
				save.click();
			}
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

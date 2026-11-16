( function () {
	document.addEventListener( 'click', function ( event ) {
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

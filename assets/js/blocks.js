( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var entities = window.wp && window.wp.htmlEntities;
	if ( ! registry || ! settings || ! element ) {
		return;
	}

	var data = settings.getSetting( 'paynet_wc_data', {} );
	var decode = entities ? entities.decodeEntities : function ( s ) { return s; };
	var title = decode( data.title || 'Paynet' );

	var Content = function () {
		return element.createElement( 'div', null, decode( data.description || '' ) );
	};
	var Label = function () {
		return element.createElement( 'span', null, title );
	};

	registry.registerPaymentMethod( {
		name: 'paynet_wc',
		label: element.createElement( Label, null ),
		content: element.createElement( Content, null ),
		edit: element.createElement( Content, null ),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: { features: data.supports || [ 'products' ] }
	} );
} )();

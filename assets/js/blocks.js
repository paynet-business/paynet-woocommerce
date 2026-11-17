( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var entities = window.wp && window.wp.htmlEntities;
	if ( ! registry || ! settings || ! element ) {
		return;
	}

	var el = element.createElement;
	var data = settings.getSetting( 'paynet_wc_data', {} );
	var decode = entities ? entities.decodeEntities : function ( s ) { return s; };
	var title = decode( data.title || 'Paynet' );
	var text = data.cardsText || {};

	function firstChoice( cards ) {
		var def = cards.filter( function ( c ) { return c.default; } )[ 0 ] || cards[ 0 ];
		return def ? String( def.id ) : 'new';
	}

	// Saved cards under the payment method (ТЗ разд. 9б); nothing when the customer has no cards.
	var Cards = function ( props ) {
		var state = element.useState( data.cards || [] );
		var cards = state[ 0 ];
		var setCards = state[ 1 ];
		var sel = element.useState( firstChoice( data.cards || [] ) );
		var selected = sel[ 0 ];
		var setSelected = sel[ 1 ];
		var err = element.useState( '' );
		var error = err[ 0 ];
		var setError = err[ 1 ];

		var reg = props.eventRegistration;
		var responses = props.emitResponse;
		element.useEffect( function () {
			if ( ! reg || ! reg.onPaymentSetup ) {
				return undefined;
			}
			return reg.onPaymentSetup( function () {
				return {
					type: responses.responseTypes.SUCCESS,
					meta: { paymentMethodData: { paynet_wc_card: selected } }
				};
			} );
		}, [ selected ] );

		if ( ! cards.length ) {
			return null;
		}

		function remove( card ) {
			var api = window.paynetWcCardsApi;
			if ( ! api ) {
				return;
			}
			api.confirm( card.label ).then( function ( yes ) {
				if ( ! yes ) {
					return;
				}
				api.remove( card.id ).then( function ( res ) {
					if ( ! res.ok ) {
						setError( res.message );
						return;
					}
					setError( '' );
					var rest = cards.filter( function ( c ) { return c.id !== card.id; } );
					setCards( rest );
					if ( String( card.id ) === selected ) {
						setSelected( rest.length ? String( rest[ 0 ].id ) : 'new' );
					}
				} );
			} );
		}

		function radio( value, label, extra ) {
			return el( 'label', null,
				el( 'input', {
					type: 'radio',
					name: 'paynet_wc_card',
					value: value,
					checked: selected === value,
					onChange: function () { setSelected( value ); }
				} ),
				' ',
				el( 'span', { className: 'paynet-wc-card-label' }, label ),
				extra ? el( 'span', { className: 'paynet-wc-card-exp' }, ' ' + extra ) : null
			);
		}

		var items = cards.map( function ( card ) {
			var exp = /99$/.test( card.expiry ) ? '' : card.expiry;
			return el( 'li', { className: 'paynet-wc-card', key: card.id },
				radio( String( card.id ), card.label, exp ),
				el( 'button', {
					type: 'button',
					className: 'paynet-wc-card-delete',
					'data-token': card.id,
					onClick: function () { remove( card ); }
				}, text.delete || 'Delete' )
			);
		} );
		items.push( el( 'li', { className: 'paynet-wc-card paynet-wc-card-new', key: 'new' }, radio( 'new', text.newCard || 'New card' ) ) );

		return el( 'div', null,
			el( 'ul', { className: 'paynet-wc-cards' }, items ),
			error ? el( 'div', { className: 'paynet-wc-card-error woocommerce-error', role: 'alert' }, error ) : null
		);
	};

	var Content = function ( props ) {
		return el( 'div', null,
			el( 'div', null, decode( data.description || '' ) ),
			el( Cards, props )
		);
	};
	var Label = function () {
		var icons = ( data.icons || [] ).map( function ( i ) {
			return el( 'img', { key: i.id, className: 'paynet-wc-icon paynet-wc-icon-' + i.id, src: i.url, alt: i.label } );
		} );
		return el( 'span', { className: 'paynet-wc-label' },
			el( 'span', null, title ),
			icons.length ? el( 'span', { className: 'paynet-wc-icons' }, icons ) : null
		);
	};

	registry.registerPaymentMethod( {
		name: 'paynet_wc',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( 'div', null, decode( data.description || '' ) ),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: { features: data.supports || [ 'products' ], showSavedCards: false, showSaveOption: false }
	} );
} )();

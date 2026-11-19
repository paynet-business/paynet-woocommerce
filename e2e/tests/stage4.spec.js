// Stage 4 — refunds and saved cards (ТЗ разд. 9, 9б, 13: scenarios 11–13, 24 (refund part), 25–32).
// Test titles start with the scenario number from ТЗ разд. 13 (used in the report and video titles).
// Real payments, refunds and card unlinks go to the Paynet TEST API. Mocks only where the test API cannot
// produce the case: CASH_INVOICE, a payment older than 180 days, paid amount less than the order, unlink 500.
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

const GUEST = { cookies: [], origins: [] };
const PAY_TIMEOUT = 300000;

let ids;
let hpos;
const shared = h.sharedState();

test.beforeEach( async ( { request } ) => {
	ids = ids || ( await h.setup( request ) );
	if ( undefined === hpos ) {
		hpos = ( await h.helperState( request ) ).hpos;
	}
	// Plugin ready in test mode with saved cards on (stage 3 sets the same through the settings page).
	await h.setOptions( request, {
		paynet_wc_settings: {
			enabled: 'yes',
			mode: 'test',
			test_username: h.TEST_CREDS.username,
			test_password: h.TEST_CREDS.password,
			test_sale_area: h.TEST_CREDS.saleArea,
			test_notify_key: h.NOTIFY_KEY,
			saved_cards: 'yes',
			debug: 'yes',
		},
		woocommerce_checkout_page_id: ids.classic_checkout,
	} );
} );

test.afterEach( async ( { page, request }, testInfo ) => {
	await h.setMocks( request, [] ).catch( () => {} );
	if ( testInfo.status === testInfo.expectedStatus ) {
		return;
	}
	const text = ( await page.locator( 'main, #wpbody-content, body' ).first().innerText().catch( () => '' ) ).replace( /\s+/g, ' ' ).replace( /Afghanistan.*?Zimbabwe/g, '[countries]' );
	console.log( `--- DIAG ${ testInfo.title }\nURL: ${ page.url() }\nPAGE: ${ text.slice( 0, 1500 ) }` );
	const log = await h.log( request ).catch( () => ( { requests: [] } ) );
	console.log( 'API: ' + JSON.stringify( log.requests.slice( -8 ).map( ( r ) => r.method + ' ' + r.path + ' → ' + ( r.response || 'mock/none' ) ) ) );
	console.log( 'CARDS: ' + JSON.stringify( await h.cards( request, ids.customer ).catch( () => null ) ) );
	const last = await h.latestOrder( request ).catch( () => 0 );
	if ( last ) {
		const o = await h.order( request, last ).catch( () => null );
		console.log( 'LATEST ORDER: ' + JSON.stringify( o && { id: o.id, status: o.status, notes: o.notes, meta: o.meta } ) );
	}
} );

function editUrl( id ) {
	return hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` : `/wp-admin/post.php?post=${ id }&action=edit`;
}

// Pay on the Paynet page and come back; the plugin confirms by GET (no notifications in test mode).
async function payAndReturn( page, request, card ) {
	await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
	const id = await h.latestOrder( request );
	await h.payOnPaynet( page, card );
	await page.waitForURL( /order-received|paynet_return/, { timeout: 120000 } ).catch( () => {} );
	await h.waitFor( async () => {
		const o = await h.runChecks( request, id );
		return 'processing' === o.status || 'completed' === o.status;
	}, { timeout: 120000, message: 'order paid' } );
	return h.order( request, id );
}

// Admin: refund through Paynet on the order page. Returns the browser dialog texts (confirm + error alert).
async function refundInAdmin( page, id, amount, reason ) {
	const dialogs = [];
	const onDialog = ( d ) => {
		dialogs.push( d.message() );
		d.accept().catch( () => {} );
	};
	page.on( 'dialog', onDialog );
	await page.goto( editUrl( id ) );
	await page.click( 'button.refund-items' );
	await page.fill( '#refund_amount', amount );
	await page.fill( '#refund_reason', reason );
	await page.waitForTimeout( 500 );
	await expect( page.locator( 'button.do-api-refund' ) ).toContainText( 'Paynet' );
	// Wait for WooCommerce's refund request to finish (Paynet may take several seconds), then for the reload / alert.
	const done = page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) && String( r.request().postData() || '' ).includes( 'woocommerce_refund_line_items' ), { timeout: 120000 } );
	await page.click( 'button.do-api-refund' );
	await done;
	await page.waitForTimeout( 3000 );
	await page.waitForLoadState( 'load', { timeout: 30000 } ).catch( () => {} );
	page.off( 'dialog', onDialog );
	return dialogs;
}

// A paid order whose Paynet data is mocked (rules the test API cannot produce).
async function mockedPaidOrder( request, product, { paid, moneyType = 'CARD', daysAgo = 0 } ) {
	const c = await h.createOrder( request, { product_id: product } );
	const amount = Math.round( Number( ( await h.order( request, c.id ) ).total ) * 100 );
	const paynetOrder = String( 900000000000 + c.id );
	await h.markPaid( request, c.id, { paynet_order: paynetOrder, payment_id: 700000 + c.id, paid, money_type: moneyType, days_ago: daysAgo } );
	await h.setMocks( request, [ { method: 'GET', path: '/api/order/merchant/', status: 200, body: h.paidStatus( { paymentId: 700000 + c.id, order: paynetOrder, amount, paidAmount: paid } ) } ] );
	return { id: c.id, amount };
}

async function refundCalls( request ) {
	return ( await h.log( request ) ).requests.filter( ( r ) => r.path === '/api/order/refund' );
}

test.describe( 'refunds', () => {
	test( '11 Full refund from the admin', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		const c = await h.createOrder( request, { product_id: ids.virtual } );
		await page.goto( c.pay_url );
		await page.click( '#place_order' );
		const o = await payAndReturn( page, request );
		expect( o.id ).toBe( c.id );

		const dialogs = await refundInAdmin( page, o.id, String( o.total ), 'E2E full refund' );
		expect( dialogs.length, 'only the confirmation, no error: ' + dialogs.join( ' | ' ) ).toBe( 1 );
		const after = await h.order( request, o.id );
		expect( after.status ).toBe( 'refunded' );
		expect( after.meta._paynet_refund.type ).toBe( 'full' );
		expect( after.notes.join( '\n' ) ).toMatch( /refund of 15\.00 MDL done \(full\)/ );
		const p = await h.paynetPayment( request, o.id );
		expect( p.data.status, 'Paynet status after a full refund' ).toBe( 5 );
	} );

	test( '12 Partial refund, then a second one is blocked', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		const c = await h.createOrder( request, { product_id: ids.virtual } );
		await page.goto( c.pay_url );
		await page.click( '#place_order' );
		const o = await payAndReturn( page, request );

		const first = await refundInAdmin( page, o.id, '5.00', 'E2E partial refund' );
		expect( first.length, first.join( ' | ' ) ).toBe( 1 );
		let after = await h.order( request, o.id );
		expect( [ 'processing', 'completed' ] ).toContain( after.status );
		expect( after.meta._paynet_refund.type ).toBe( 'partial' );
		expect( ( await h.paynetPayment( request, o.id ) ).data.status, 'Paynet status after a partial refund' ).toBe( 6 );

		const before = ( await refundCalls( request ) ).length;
		const second = await refundInAdmin( page, o.id, '3.00', 'E2E second refund' );
		expect( second.join( ' | ' ) ).toMatch( /one refund per order/ );
		expect( ( await refundCalls( request ) ).length, 'no second refund request to Paynet' ).toBe( before );
		after = await h.order( request, o.id );
		expect( after.notes.join( '\n' ) ).not.toMatch( /refund of 3\.00 MDL done/ );
	} );

	test( '13 Refund blocked: CASH_INVOICE and paid more than 180 days ago (mocks)', async ( { page, request } ) => {
		const before = ( await refundCalls( request ) ).length;
		const cash = await mockedPaidOrder( request, ids.virtual, { paid: 1500, moneyType: 'CASH_INVOICE' } );
		const d1 = await refundInAdmin( page, cash.id, '15.00', 'E2E cash invoice' );
		expect( d1.join( ' | ' ) ).toContain( 'CASH_INVOICE' );

		const old = await mockedPaidOrder( request, ids.virtual, { paid: 1500, daysAgo: 200 } );
		const d2 = await refundInAdmin( page, old.id, '15.00', 'E2E old payment' );
		expect( d2.join( ' | ' ) ).toContain( 'within 180 days' );
		expect( ( await refundCalls( request ) ).length, 'no refund request reached Paynet' ).toBe( before );
		expect( ( await h.order( request, old.id ) ).status ).toBe( 'processing' );
	} );

	test( '24 Paid less than the order: refund above the paid 2970 MDL is blocked', async ( { page, request } ) => {
		const before = ( await refundCalls( request ) ).length;
		const o = await mockedPaidOrder( request, ids.big, { paid: 297000 } );
		expect( o.amount ).toBe( 330000 );
		const d = await refundInAdmin( page, o.id, '3300.00', 'E2E above paid' );
		expect( d.join( ' | ' ) ).toContain( 'more than the amount paid through Paynet (2970.00 MDL)' );
		expect( ( await refundCalls( request ) ).length ).toBe( before );
	} );
} );

test.describe( 'saved cards', () => {
	test.use( { storageState: GUEST } );

	test( '25 Customer without saved cards: nothing under the payment method', async ( { page, request } ) => {
		await h.clearCards( request, ids.customer );
		await h.login( page );
		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await page.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		await expect( page.locator( 'li.payment_method_paynet_wc' ) ).toContainText( 'Paynet' );
		await expect( page.locator( '.paynet-wc-cards' ) ).toHaveCount( 0 );

		await page.goto( '/?page_id=' + ids.blocks_checkout );
		await page.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
		await expect( page.getByText( 'Card, Apple Pay, Google Pay (Paynet)' ).first() ).toBeVisible();
		await expect( page.locator( '.paynet-wc-cards' ) ).toHaveCount( 0 );
	} );

	test( '26 Registered customer pays with "save card": card in the list and in My account', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.clearCards( request, ids.customer );
		await h.login( page );
		await h.addToCart( page, ids.product );
		await h.classicPlaceOrder( page, ids, { email: 'e2e-customer@example.com' } );
		const o = await payAndReturn( page, request, h.CARD );
		expect( o.notes.join( '\n' ) ).toContain( 'Visa •••• 1111 saved' );
		const list = await h.cards( request, ids.customer );
		expect( list ).toHaveLength( 1 );
		expect( list[ 0 ] ).toMatchObject( { last4: '1111', type: 'visa', mode: 'test' } );

		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await page.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		await expect( page.locator( '.paynet-wc-cards' ) ).toContainText( 'Visa •••• 1111' );
		await expect( page.locator( '.paynet-wc-cards' ) ).toContainText( 'New card' );

		await page.goto( ( await h.helperState( request ) ).payment_methods );
		await expect( page.locator( '.woocommerce-MyAccount-paymentMethods, .woocommerce-PaymentMethods' ).first() ).toContainText( 'Visa •••• 1111' );
		await page.waitForTimeout( 1500 );
	} );

	test( '27 Guest pays with "save card": the card is not saved', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.addToCart( page, ids.product );
		await h.classicPlaceOrder( page, ids, { email: 'e2e-guest-card@example.com' } );
		const o = await payAndReturn( page, request, h.CARD );
		expect( o.notes.join( '\n' ) ).not.toContain( 'saved for the customer' );
		const p = await h.paynetPayment( request, o.id );
		expect( p.data.customer.code ).toBe( 'guest-' + o.id );
		expect( p.data.cardLinkHash, 'Paynet issued a token for the guest code, the plugin ignored it' ).toBeTruthy();
	} );

	test( '28 Two different cards: two lines, the last one preselected', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.login( page );
		await h.addToCart( page, ids.product );
		// The Visa from scenario 26 is preselected (last used card): choose "New card" to enter the Mastercard.
		await h.classicPlaceOrder( page, ids, { email: 'e2e-customer@example.com', card: 'New card' } );
		await payAndReturn( page, request, h.CARD_MC );
		const list = await h.cards( request, ids.customer );
		expect( list.map( ( c ) => c.last4 ).sort() ).toEqual( [ '1111', '4444' ] );
		expect( list.find( ( c ) => c.last4 === '4444' ).default ).toBe( true );

		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await page.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		await expect( page.locator( '.paynet-wc-cards li' ) ).toHaveCount( 3 );
		await expect( page.locator( '.paynet-wc-card', { hasText: 'Mastercard •••• 4444' } ).locator( 'input' ) ).toBeChecked();

		await page.goto( '/?page_id=' + ids.blocks_checkout );
		await page.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
		const radio = page.locator( '#radio-control-wc-payment-method-options-paynet_wc' );
		if ( ( await radio.count() ) && ( await radio.isVisible() ) ) {
			await radio.check();
		}
		await expect( page.locator( '.paynet-wc-cards li' ) ).toHaveCount( 3 );
		await expect( page.locator( '.paynet-wc-card', { hasText: 'Mastercard •••• 4444' } ).locator( 'input' ) ).toBeChecked();
		await page.waitForTimeout( 1500 );
	} );

	test( '29 Pay with a saved card: Paynet shows the chosen card', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		const visa = ( await h.cards( request, ids.customer ) ).find( ( c ) => c.last4 === '1111' );
		expect( visa, 'Visa saved in scenario 26' ).toBeTruthy();
		await h.login( page );
		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await page.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		await page.locator( '.paynet-wc-card', { hasText: 'Visa •••• 1111' } ).locator( 'input' ).check();
		await h.waitUnblocked( page );
		await page.click( '#place_order' );
		await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
		const id = await h.latestOrder( request );

		const sent = ( await h.log( request ) ).requests.filter( ( r ) => r.path === '/api/order' ).pop();
		expect( String( sent.body.CardLinkHash || '' ).startsWith( visa.hash ), 'CardLinkHash of the chosen card sent' ).toBe( true );
		const salt = ( await h.helperState( request ) ).salt;
		expect( sent.body.Customer.Code, 'test mode: salt + user ID' ).toBe( `${ salt }-${ ids.customer }` );

		// Saved-card mode of the Paynet page: the "new card" switch is shown and not selected, no card number field.
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } ).catch( () => {} );
		await page.waitForTimeout( 3000 );
		await expect( page.locator( '#new-card' ) ).toBeVisible();
		await expect( page.locator( '#new-card' ) ).not.toBeChecked();
		await page.click( '#submit-default' );
		await page.waitForURL( /order-received|paynet_return/, { timeout: 120000 } ).catch( () => {} );
		await h.waitFor( async () => 'processing' === ( await h.runChecks( request, id ) ).status, { timeout: 120000, message: 'order paid' } );
		expect( ( await h.paynetPayment( request, id ) ).data.payer.cardMask ).toBe( '411111******1111' );
		const list = await h.cards( request, ids.customer );
		expect( list.find( ( c ) => c.last4 === '1111' ).default, 'last used card preselected next time' ).toBe( true );
		expect( list ).toHaveLength( 2 );
	} );

	test( '30 Delete a card: "Cancel" keeps it, "Yes" unlinks it in Paynet', async ( { page, request } ) => {
		await h.login( page );
		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await page.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		const mc = page.locator( '.paynet-wc-card', { hasText: 'Mastercard •••• 4444' } );
		await mc.locator( '.paynet-wc-card-delete' ).click();
		await expect( page.locator( '.paynet-wc-modal' ) ).toContainText( 'Do you really want to delete the card Mastercard •••• 4444?' );
		await page.waitForTimeout( 800 );
		await page.click( '.paynet-wc-modal-no' );
		await expect( page.locator( '.paynet-wc-modal' ) ).toHaveCount( 0 );
		await expect( mc ).toBeVisible();
		expect( await h.cards( request, ids.customer ) ).toHaveLength( 2 );

		await mc.locator( '.paynet-wc-card-delete' ).click();
		await page.waitForTimeout( 800 );
		await page.click( '.paynet-wc-modal-yes' );
		await expect( mc ).toHaveCount( 0, { timeout: 30000 } );
		const list = await h.cards( request, ids.customer );
		expect( list.map( ( c ) => c.last4 ) ).toEqual( [ '1111' ] );
		const unlink = ( await h.log( request ) ).requests.filter( ( r ) => r.path === '/api/linked-cards/unlink' ).pop();
		expect( unlink.response ).toMatch( /^200/ );
		expect( Object.keys( unlink.body ).sort() ).toEqual( [ 'CardLinkHash', 'CustomerCode', 'SaleAreaCode' ] );
		await page.waitForTimeout( 1500 );
	} );

	test( '30 Delete a card in the Block checkout: one window with the card name, readable button, "Cancel" keeps the card', async ( { page, request } ) => {
		await h.login( page );
		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.blocks_checkout );
		await page.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
		const radio = page.locator( '#radio-control-wc-payment-method-options-paynet_wc' );
		if ( ( await radio.count() ) && ( await radio.isVisible() ) ) {
			await radio.check();
		}
		const visa = page.locator( '.paynet-wc-card', { hasText: 'Visa •••• 1111' } );
		const del = visa.locator( '.paynet-wc-card-delete' );
		await del.hover();
		await page.waitForTimeout( 600 );
		expect( await del.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ), 'the theme must not paint the button dark on hover' ).toBe( 'rgba(0, 0, 0, 0)' );
		await del.click();
		await expect( page.locator( '.paynet-wc-modal' ), 'exactly one window' ).toHaveCount( 1 );
		await expect( page.locator( '.paynet-wc-modal' ) ).toContainText( 'Do you really want to delete the card Visa •••• 1111?' );
		await page.waitForTimeout( 800 );
		await page.click( '.paynet-wc-modal-no' );
		await expect( page.locator( '.paynet-wc-modal' ) ).toHaveCount( 0 );
		await expect( visa ).toBeVisible();
		expect( await h.cards( request, ids.customer ) ).toHaveLength( 1 );
		await page.waitForTimeout( 1500 );
	} );

	test( '31 Unlink answers 404 (card removed in WP) / 500 (card kept, message)', async ( { page, request } ) => {
		const gone = await h.addCard( request, ids.customer, { hash: '1_00000000000000000000000000000404', last4: '0404', month: '01', year: '2031' } );
		await h.login( page );
		await page.goto( ( await h.helperState( request ) ).payment_methods );
		const row = page.locator( 'tr', { hasText: '•••• 0404' } );
		await row.locator( 'a.delete' ).click();
		await page.waitForTimeout( 800 );
		await page.click( '.paynet-wc-modal-yes' );
		await expect( row ).toHaveCount( 0, { timeout: 30000 } );
		expect( ( await h.cards( request, ids.customer ) ).find( ( c ) => c.id === gone.id ), 'real API 404 → removed in WordPress' ).toBeUndefined();
		const unlink = ( await h.log( request ) ).requests.filter( ( r ) => r.path === '/api/linked-cards/unlink' ).pop();
		expect( unlink.response ).toMatch( /^404/ );

		const kept = await h.addCard( request, ids.customer, { hash: '1_00000000000000000000000000000500', last4: '0500', month: '01', year: '2031' } );
		await h.setMocks( request, [ { method: 'POST', path: '/api/linked-cards/unlink', status: 500, body: { status: 500, message: 'One or more errors occurred.', code: 'TECHNICAL_ERROR', traceId: 'e2e' } } ] );
		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await page.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		await page.locator( '.paynet-wc-card', { hasText: '•••• 0500' } ).locator( '.paynet-wc-card-delete' ).click();
		await page.waitForTimeout( 800 );
		await page.click( '.paynet-wc-modal-yes' );
		await expect( page.locator( '.paynet-wc-card-error' ) ).toContainText( 'Could not delete the card, please try again later.', { timeout: 30000 } );
		await expect( page.locator( '.paynet-wc-card', { hasText: '•••• 0500' } ) ).toBeVisible();
		expect( ( await h.cards( request, ids.customer ) ).find( ( c ) => c.id === kept.id ), 'card kept after 500' ).toBeTruthy();
		await h.setMocks( request, [] );
		await page.waitForTimeout( 1500 );
	} );

	test( '32 Another user cannot delete someone else\'s card', async ( { page, request } ) => {
		const visa = ( await h.cards( request, ids.customer ) ).find( ( c ) => c.last4 === '1111' );
		expect( visa ).toBeTruthy();
		await h.createUser( request, 'e2e-other', 'e2e-other-pass' );
		await h.login( page, 'e2e-other', 'e2e-other-pass' );
		await page.goto( ( await h.helperState( request ) ).payment_methods );
		const res = await page.evaluate( async ( token ) => {
			const d = new FormData();
			d.append( 'action', 'paynet_wc_delete_card' );
			d.append( 'nonce', window.paynetWcCards.nonce );
			d.append( 'token', token );
			const r = await fetch( window.paynetWcCards.ajax, { method: 'POST', credentials: 'same-origin', body: d } );
			return { status: r.status, body: await r.text() };
		}, visa.id );
		expect( res.status ).toBe( 403 );
		expect( res.body ).toContain( 'cannot be deleted' );
		expect( ( await h.cards( request, ids.customer ) ).find( ( c ) => c.id === visa.id ), 'card untouched' ).toBeTruthy();
		const unlinks = ( await h.log( request ) ).requests.filter( ( r ) => r.path === '/api/linked-cards/unlink' && String( r.body.CardLinkHash ).startsWith( visa.hash ) );
		expect( unlinks, 'no unlink request for the other user' ).toHaveLength( 0 );
	} );
} );

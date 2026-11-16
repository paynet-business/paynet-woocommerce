// Stage 3 — payment: classic + Blocks + HPOS, notifications, background checks (ТЗ разд. 13: 1–10, 14–15, 18–24, 33).
// Test titles start with the scenario number from ТЗ разд. 13 (used in the report and video titles).
// Real payments go through the Paynet TEST API and payment page; notifications are simulated (signed with the
// key set in the plugin), the status is always confirmed by the real GET. Mocks only where the test API cannot
// produce the case: decline, network failure, paid amount differs.
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

const SETTINGS = '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=paynet_wc';
const GUEST = { cookies: [], origins: [] };
const PAY_TIMEOUT = 240000;

// Not serial: a failed scenario must not skip the others (each one gets its own ✓/✗ in the report).
// State shared between scenarios lives in a file, so it survives worker restarts after a failure.
let ids;
const shared = h.sharedState();

test.beforeEach( async ( { request } ) => {
	ids = ids || ( await h.setup( request ) );
} );

// On failure: where the browser was and what it showed — readable in the CI log (artifacts are for people).
test.afterEach( async ( { page, request }, testInfo ) => {
	if ( testInfo.status === testInfo.expectedStatus ) {
		return;
	}
	const text = ( await page.locator( 'main, #wpbody-content, body' ).first().innerText().catch( () => '' ) ).replace( /\s+/g, ' ' ).replace( /Afghanistan.*?Zimbabwe/g, '[countries]' );
	console.log( `--- DIAG ${ testInfo.title }\nURL: ${ page.url() }\nPAGE: ${ text.slice( 0, 1500 ) }` );
	const state = await h.helperState( request ).catch( ( e ) => ( { error: String( e ) } ) );
	console.log( 'STATE: ' + JSON.stringify( state ) );
	const log = await h.log( request ).catch( () => ( { requests: [] } ) );
	console.log( 'API: ' + JSON.stringify( log.requests.slice( -6 ).map( ( r ) => r.method + ' ' + r.path + ' → ' + ( r.response || 'mock/none' ) ) ) );
	const last = await h.latestOrder( request ).catch( () => 0 );
	if ( last ) {
		const o = await h.order( request, last ).catch( () => null );
		console.log( 'LATEST ORDER: ' + JSON.stringify( o && { id: o.id, status: o.status, notes: o.notes, meta: o.meta } ) );
	}
} );

async function paynetMails( request ) {
	return ( await h.log( request ) ).mails.filter( ( m ) => String( m.subject ).startsWith( 'Paynet:' ) );
}

// The browser request that went to the Paynet page (the page itself may redirect further).
function paynetNavigation( page ) {
	return page.waitForRequest( ( r ) => r.isNavigationRequest() && r.url().includes( 'paynet.md' ), { timeout: 60000 } );
}

async function placeClassic( page, request, opts = {} ) {
	await h.setOptions( request, { woocommerce_checkout_page_id: ids.classic_checkout } );
	await h.classicPlaceOrder( page, ids, opts );
}

async function createdOrder( page, request ) {
	await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
	const id = await h.latestOrder( request );
	const o = await h.order( request, id );
	expect( o.meta._paynet_order, 'Paynet Order saved' ).toBeTruthy();
	expect( o.meta._paynet_payment_link ).toContain( '/ecom/getecom/' + o.meta._paynet_payment_id );
	return o;
}

async function paidByNotification( request, o, extra = {} ) {
	const n = h.notification( {
		paymentId: o.meta._paynet_payment_id,
		externalId: o.meta._paynet_order,
		amount: Number( o.meta._paynet_amount ),
		...extra,
	} );
	return { n, res: await h.sendNotification( request, n ) };
}

test.describe( 'admin', () => {
	test( 'S3-1 Settings: "Fill with test credentials" and "Check connection" (✓ and ✗)', async ( { page, request } ) => {
		await page.goto( SETTINGS );
		await page.selectOption( '#woocommerce_paynet_wc_mode', 'test' );
		await page.check( '#woocommerce_paynet_wc_enabled' );
		await page.click( '.paynet-wc-test-fill' );
		await expect( page.locator( '#woocommerce_paynet_wc_test_username' ) ).toHaveValue( h.TEST_CREDS.username );
		await expect( page.locator( '#woocommerce_paynet_wc_test_sale_area' ) ).toHaveValue( h.TEST_CREDS.saleArea );
		await Promise.all( [ page.waitForNavigation(), page.click( 'button[name="save"]' ) ] );

		await page.click( '.paynet-wc-check' );
		await expect( page.locator( '.paynet-wc-check-result' ) ).toContainText( '✓ Connected', { timeout: 30000 } );

		await page.fill( '#woocommerce_paynet_wc_test_password', 'wrong-password' );
		await page.click( '.paynet-wc-check' );
		await expect( page.locator( '.paynet-wc-check-result' ) ).toContainText( '✗', { timeout: 30000 } );
		await expect( page.locator( '.paynet-wc-check-result' ) ).toContainText( 'AUTHENTICATION_FAILED' );
		await page.waitForTimeout( 1500 );

		// The notification key used to sign simulated notifications (ТЗ разд. 13).
		await h.setOptions( request, { paynet_wc_settings: { test_notify_key: h.NOTIFY_KEY, debug: 'yes' } } );
	} );

	test( '21 Order storage of this run (HPOS on/off is a CI matrix: all scenarios run in both)', async ( { request } ) => {
		const state = await h.helperState( request );
		expect( state.hpos ).toBe( 'off' !== process.env.HPOS );
	} );
} );

test.describe( 'customer', () => {
	test.use( { storageState: GUEST } );

	test( '1 Classic checkout, test card: simulated notification, status confirmed by GET', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		expect( o.status ).toBe( 'pending' );
		expect( o.notes.join( '\n' ) ).toContain( `Paynet payment #${ o.meta._paynet_payment_id } created` );

		const returned = await h.blockReturn( page );
		await h.payOnPaynet( page );
		await h.waitFor( returned, { message: 'redirect back to the shop' } );
		expect( ( await h.order( request, o.id ) ).status ).toBe( 'pending' );

		const { n, res } = await paidByNotification( request, o );
		expect( res.status ).toBe( 200 );
		expect( res.body ).toContain( 'SUCCESS' );
		const after = await h.order( request, o.id );
		expect( after.status ).toBe( 'processing' );
		expect( after.transaction_id ).toBe( String( o.meta._paynet_payment_id ) );
		expect( after.notes.join( '\n' ) ).toContain( `payment #${ o.meta._paynet_payment_id } confirmed` );

		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		await page.goto( returned() );
		await expect( page.locator( 'body' ) ).toContainText( /Order received|Thank you/i );
		shared.s1 = { id: o.id, n, paynetOrder: o.meta._paynet_order };
	} );

	test( '2 Block checkout (Blocks), test card', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.addToCart( page, ids.product );
		await h.setOptions( request, { woocommerce_checkout_page_id: ids.blocks_checkout } );
		await h.blocksPlaceOrder( page, ids );
		const o = await createdOrder( page, request );
		await h.payOnPaynet( page );
		await page.waitForURL( /order-received/, { timeout: 120000 } );
		await expect( page.locator( 'body' ) ).toContainText( /Order received|Thank you/i );
		const after = await h.order( request, o.id );
		expect( after.status ).toBe( 'processing' );
		await h.setOptions( request, { woocommerce_checkout_page_id: ids.classic_checkout } );
	} );

	test( '3 Return to the site before the notification: "being processed", then the status is updated', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		// Paynet still processing at the moment of the return (status 3), then the background check sees the real status.
		await h.setMocks( request, [ { method: 'GET', path: '/api/order/merchant/', status: 200, body: h.paidStatus( { paymentId: o.meta._paynet_payment_id, order: o.meta._paynet_order, amount: 0, status: 3 } ), times: 1 } ] );
		await h.payOnPaynet( page );
		await page.waitForURL( /paynet_pending=1/, { timeout: 120000 } );
		await expect( page.locator( '.paynet-wc-pending' ) ).toContainText( 'The payment is being processed' );
		const pending = await h.order( request, o.id );
		expect( pending.status ).toBe( 'pending' );
		expect( pending.checks.length ).toBeGreaterThan( 0 );

		const checked = await h.runChecks( request, o.id );
		expect( checked.status ).toBe( 'processing' );
		await page.reload();
		await expect( page.locator( '.paynet-wc-pending' ) ).toHaveCount( 0 );
	} );

	test( '4 Left the Paynet page, "Pay" from My account → the same paymentLink', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		const state = await h.helperState( request );
		await h.login( page );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );

		await page.goto( state.myaccount );
		await page.click( 'a:has-text("Orders")' );
		await page.locator( `tr:has(a:has-text("#${ o.id }")) a.pay` ).click();
		const nav = paynetNavigation( page );
		await page.click( '#place_order' );
		expect( ( await nav ).url() ).toBe( o.meta._paynet_payment_link );
		const again = await h.order( request, o.id );
		expect( again.meta._paynet_order ).toBe( o.meta._paynet_order );
		shared.s4 = o;
	} );

	test( '5 Payment window expired, pay again → new Order, payment succeeds', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		const before = shared.s4;
		await h.setMeta( request, before.id, { _paynet_expiry_ts: Math.floor( Date.now() / 1000 ) - 60 } );
		await h.login( page );
		await page.goto( before.pay_url );
		const nav = paynetNavigation( page );
		await page.click( '#place_order' );
		const navUrl = ( await nav ).url();
		const o = await h.order( request, before.id );
		expect( o.meta._paynet_order ).not.toBe( before.meta._paynet_order );
		expect( Number( o.meta._paynet_attempt ) ).toBe( Number( before.meta._paynet_attempt ) + 1 );
		expect( navUrl ).toBe( o.meta._paynet_payment_link );
		expect( navUrl ).not.toBe( before.meta._paynet_payment_link );
		await h.payOnPaynet( page );
		await page.waitForURL( /order-received/, { timeout: 120000 } );
		expect( ( await h.order( request, o.id ) ).status ).toBe( 'processing' );
	} );

	test( '6 Bank declined (mock status 5): failed, "Try again" → new attempt', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		const declined = h.paidStatus( { paymentId: o.meta._paynet_payment_id, order: o.meta._paynet_order, amount: Number( o.meta._paynet_amount ), status: 5 } );
		await h.setMocks( request, [ { method: 'GET', path: '/api/order/merchant/', status: 200, body: declined, times: 2 } ] );
		// The customer comes back from the Paynet page after the decline.
		await page.goto( `/?wc-api=WC_Gateway_Paynet&paynet_return=cancel&order=${ o.id }&key=${ o.key }` );
		await expect( page.locator( '.woocommerce-error, .wc-block-components-notice-banner' ).first() ).toContainText( 'The payment was not completed' );
		const failed = await h.order( request, o.id );
		expect( failed.status ).toBe( 'failed' );
		expect( failed.notes.join( '\n' ) ).toContain( 'declined or cancelled' );

		await page.click( '#place_order' );
		await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
		const retry = await h.order( request, o.id );
		expect( retry.meta._paynet_order ).not.toBe( o.meta._paynet_order );
		expect( retry.status ).toBe( 'pending' );
		shared.s6 = retry;
	} );

	test( '7 Notification with a wrong Hash → 400, status unchanged', async ( { request } ) => {
		const o = shared.s6;
		const n = h.notification( { paymentId: o.meta._paynet_payment_id, externalId: o.meta._paynet_order, amount: Number( o.meta._paynet_amount ) } );
		const res = await h.sendNotification( request, n, 'AAAAAAAAAAAAAAAAAAAAAA==' );
		expect( res.status ).toBe( 400 );
		const signedWithOtherKey = h.notification( { paymentId: o.meta._paynet_payment_id, externalId: o.meta._paynet_order, amount: Number( o.meta._paynet_amount ), key: 'D9873689-E5F8-4272-A67F-0D8BA3F9BAA7' } );
		expect( ( await h.sendNotification( request, signedWithOtherKey ) ).status ).toBe( 400 );
		expect( ( await h.order( request, o.id ) ).status ).toBe( 'pending' );
	} );

	test( '8 Valid Hash, tampered PaidAmount → result by GET, mismatch → on-hold', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		const returned = await h.blockReturn( page );
		await h.payOnPaynet( page );
		await h.waitFor( returned, { message: 'redirect back to the shop' } );

		const mailsBefore = ( await paynetMails( request ) ).length;
		const { res } = await paidByNotification( request, o, { paidAmount: Number( o.meta._paynet_amount ) - 100 } );
		expect( res.status ).toBe( 200 );
		const after = await h.order( request, o.id );
		expect( after.status ).toBe( 'on-hold' );
		expect( after.notes.join( '\n' ) ).toContain( 'amount mismatch' );
		expect( after.notes.join( '\n' ) ).toContain( 'PaidAmount in the notification' );
		expect( ( await paynetMails( request ) ).length ).toBe( mailsBefore + 1 );
	} );

	test( '9 Repeated notification → no duplicate notes or e-mails', async ( { request } ) => {
		const before = await h.order( request, shared.s1.id );
		const mailsBefore = ( await h.log( request ) ).mails.length; // All e-mails, WooCommerce ones included.
		for ( let i = 0; i < 2; i++ ) {
			const res = await h.sendNotification( request, shared.s1.n );
			expect( res.status ).toBe( 200 );
		}
		const after = await h.order( request, shared.s1.id );
		expect( after.notes.length ).toBe( before.notes.length );
		expect( after.status ).toBe( 'processing' );
		expect( ( await h.log( request ) ).mails.length ).toBe( mailsBefore );
	} );

	test( '10 Old link ?wc-api=WC_Gateway_Paynet&result=error for a paid order → nothing changes', async ( { page, request } ) => {
		const before = await h.order( request, shared.s1.id );
		await page.goto( `/?wc-api=WC_Gateway_Paynet&id=${ shared.s1.paynetOrder }&result=error` );
		expect( page.url() ).not.toContain( 'wc-api' );
		const after = await h.order( request, shared.s1.id );
		expect( after.status ).toBe( 'processing' );
		expect( after.notes.length ).toBe( before.notes.length );
	} );

	test( '14 API unavailable when creating the payment → message, order not cancelled', async ( { page, request } ) => {
		await h.setMocks( request, [ { method: 'POST', path: '/api/order', network_error: true, times: 2 } ] );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		await expect( page.locator( '.woocommerce-error, .woocommerce-NoticeGroup-checkout' ).first() ).toContainText( 'Could not proceed to payment', { timeout: 60000 } );
		const o = await h.order( request, await h.latestOrder( request ) );
		expect( o.status ).toBe( 'pending' );
		expect( o.notes.join( '\n' ) ).toContain( 'NETWORK' );
		await h.setMocks( request, [] );
	} );

	test( '15 API unavailable during the notification → 200, status updated by the background check', async ( { page, request } ) => {
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		await h.setMocks( request, [ { method: 'GET', path: '/api/order/merchant/', network_error: true, times: 1 } ] );
		const { res } = await paidByNotification( request, o );
		expect( res.status ).toBe( 200 );
		const waiting = await h.order( request, o.id );
		expect( waiting.status ).toBe( 'pending' );
		expect( waiting.checks.some( ( c ) => c.at - waiting.now <= 70 ) ).toBe( true );

		// The API answers again: paid (the order was not paid on the real page, so the answer is mocked).
		const amount = Number( o.meta._paynet_amount );
		await h.setMocks( request, [ { method: 'GET', path: '/api/order/merchant/', status: 200, body: h.paidStatus( { paymentId: o.meta._paynet_payment_id, order: o.meta._paynet_order, amount, paidAmount: amount } ), times: 5 } ] );
		const checked = await h.runChecks( request, o.id );
		expect( checked.status ).toBe( 'processing' );
		await h.setMocks( request, [] );
	} );

	test( '18 Name "Mary\'s & <Co>", Cyrillic, line break → the order is created', async ( { page, request } ) => {
		await h.addToCart( page, ids.special );
		await h.setOptions( request, { woocommerce_checkout_page_id: ids.classic_checkout } );
		await h.classicPlaceOrder( page, ids, { first: "Mary's & <Co>", last: 'Țurcan Мария' } );
		const o = await createdOrder( page, request );
		const body = ( await h.log( request ) ).requests.filter( ( r ) => 'POST' === r.method && r.path === '/api/order' ).pop().body;
		expect( body.Customer.NameFirst ).toBe( "Mary's &" );
		expect( body.Customer.NameLast ).toBe( 'Țurcan Мария' );
		expect( body.Services[ 0 ].Products[ 0 ].Name ).toBe( 'Mary\'s & Торт "Наполеон" Țară' );
		expect( JSON.stringify( body ) ).not.toMatch( /[<>]|&#0?39;|\\n/ );
		expect( o.status ).toBe( 'pending' );
	} );

	test( '19 3 × 19.99 + coupon 10 % + shipping → sum of lines = total', async ( { page, request } ) => {
		await h.addToCart( page, ids.product, 3 );
		await placeClassic( page, request, { coupon: 'e2e10' } );
		const o = await createdOrder( page, request );
		const body = ( await h.log( request ) ).requests.filter( ( r ) => 'POST' === r.method && r.path === '/api/order' ).pop().body;
		const service = body.Services[ 0 ];
		const sum = service.Products.reduce( ( a, p ) => a + p.Amount, 0 );
		const total = Math.round( Number( o.total ) * 100 );
		expect( total ).toBeGreaterThan( 10390 ); // ≈ 53.97 (3 × 19.99 − 10 %) + 50.00 shipping.
		expect( total ).toBeLessThan( 10400 );
		expect( service.Amount ).toBe( total );
		expect( sum ).toBe( total );
		expect( service.Products.length ).toBe( 2 ); // product line + shipping line.
		for ( const p of service.Products ) {
			expect( p.UnitPrice * p.Quantity / 100 ).toBe( p.Amount );
		}
	} );

	test( '22 "Hold stock" 30 min, payment window 60 → ExpiryDate = +30 min', async ( { page, request } ) => {
		await h.setOptions( request, { woocommerce_hold_stock_minutes: '30' } );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		const window = Number( o.meta._paynet_expiry_ts ) - o.created;
		expect( window ).toBeGreaterThanOrEqual( 1800 - 5 );
		expect( window ).toBeLessThanOrEqual( 1800 + 120 );
		expect( o.meta._paynet_expiry ).toMatch( /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/ );
		await h.setOptions( request, { woocommerce_hold_stock_minutes: '60' } );
	} );

	test( '23 Test: Order number already used by another user → retry with a new attempt; twice → hint note', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		const state = await h.helperState( request );
		const x = await h.createOrder( request, { product_id: ids.product, customer_id: ids.customer } );
		await h.occupyOrder( state.salt, x.id, 1 );

		await h.login( page );
		await page.goto( x.pay_url );
		await page.click( '#place_order' );
		await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
		const o = await h.order( request, x.id );
		expect( Number( o.meta._paynet_attempt ) ).toBe( 2 );
		expect( o.notes.join( '\n' ) ).toContain( 'retrying with a new attempt number' );

		const y = await h.createOrder( request, { product_id: ids.product, customer_id: ids.customer } );
		await h.occupyOrder( state.salt, y.id, 1 );
		await h.occupyOrder( state.salt, y.id, 2 );
		await page.goto( y.pay_url );
		await page.click( '#place_order' );
		await expect( page.locator( '.woocommerce-error, .woocommerce-NoticeGroup' ).first() ).toContainText( 'Could not proceed to payment', { timeout: 60000 } );
		const oy = await h.order( request, y.id );
		expect( oy.notes.join( '\n' ) ).toContain( 'Regenerate' );
		expect( oy.status ).toBe( 'pending' );
	} );

	test( '24 Paid less than the order (mock 2970 of 3300 MDL) → paid, note, e-mail to admin', async ( { page, request } ) => {
		await h.addToCart( page, ids.big );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		expect( Number( o.meta._paynet_amount ) ).toBe( 330000 );
		const mailsBefore = ( await paynetMails( request ) ).length;
		await h.setMocks( request, [ { method: 'GET', path: '/api/order/merchant/', status: 200, body: h.paidStatus( { paymentId: o.meta._paynet_payment_id, order: o.meta._paynet_order, amount: 330000, paidAmount: 297000 } ), times: 5 } ] );
		const after = await h.runChecks( request, o.id );
		await h.setMocks( request, [] );
		expect( after.status ).toBe( 'processing' );
		expect( after.meta._paynet_amount_mismatch ).toBe( 'less' );
		expect( after.notes.join( '\n' ) ).toContain( 'the paid amount (2970.00 MDL) is less than the order amount (3300.00 MDL). Check whether there was a discount.' );
		const mails = await paynetMails( request );
		expect( mails.length ).toBe( mailsBefore + 1 );
		expect( mails[ mails.length - 1 ].message ).toContain( 'Check whether there was a discount' );
		shared.s24 = o.id;
	} );

	test( '33 Test mode: paid, no notification, customer closed the tab → background check sets processing', async ( { page, request } ) => {
		test.setTimeout( PAY_TIMEOUT );
		await h.addToCart( page, ids.product );
		await placeClassic( page, request );
		const o = await createdOrder( page, request );
		const first = o.checks.map( ( c ) => c.at - o.created ).sort( ( a, b ) => a - b )[ 0 ];
		expect( first ).toBeGreaterThanOrEqual( 115 );
		expect( first ).toBeLessThanOrEqual( 180 );

		const returned = await h.blockReturn( page );
		await h.payOnPaynet( page );
		await h.waitFor( returned, { message: 'redirect back to the shop' } );
		expect( ( await h.order( request, o.id ) ).status ).toBe( 'pending' );

		// The check scheduled for +2 min runs now.
		const checked = await h.runChecks( request, o.id );
		expect( checked.ran ).toBeGreaterThan( 0 );
		expect( checked.status ).toBe( 'processing' );
	} );
} );

test.describe( 'admin checks', () => {
	test( '20 Test mode + USD → warning in the settings', async ( { page, request } ) => {
		await h.setOptions( request, { woocommerce_currency: 'USD' } );
		await page.goto( SETTINGS );
		await expect( page.locator( '.paynet-wc-currency-notice' ) ).toContainText( 'Only MDL is available in the Paynet test environment' );
		await h.setOptions( request, { woocommerce_currency: 'MDL' } );
		await page.reload();
		await expect( page.locator( '.paynet-wc-currency-notice' ) ).toHaveCount( 0 );
	} );

	test( '24 Orders list: mismatch icon and filter "Paid amount differs"', async ( { page, request } ) => {
		const state = await h.helperState( request );
		const list = state.hpos ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order';
		await page.goto( list );
		const link = ( id ) => ( state.hpos ? `a[href$="&id=${ id }"]` : `a[href*="post=${ id }&"]` );
		const row = page.locator( `tr:has(${ link( shared.s24 ) })` ).first();
		await expect( row.locator( '.paynet-wc-mismatch' ) ).toBeVisible();
		await page.goto( list + '&paynet_wc_mismatch=1' );
		await expect( page.locator( '.paynet-wc-mismatch' ).first() ).toBeVisible();
		await expect( page.locator( link( shared.s1.id ) ) ).toHaveCount( 0 );
	} );

	test( '33 Settings: the test-mode notice explains why notifications do not arrive', async ( { page } ) => {
		await page.goto( SETTINGS );
		await expect( page.locator( '.paynet-wc-test-notice' ) ).toContainText( 'Payment notifications do not reach your site' );
		await expect( page.locator( '.paynet-wc-test-notice' ) ).toContainText( 'background check' );
	} );
} );

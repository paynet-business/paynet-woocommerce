// Stage 5 — switching from the old plugin "Paynet Moldova Gateway" (ТЗ разд. 11, 13: scenarios 16–17, В-3).
// Test titles start with the scenario number from ТЗ разд. 13 (used in the report and video titles).
// The old plugin is the real one (e2e/fixtures/paynet-gateway, v1.1.5). Its orders are created with its own
// v0.5 payment form and paid on the Paynet TEST page; the new plugin confirms them through the new API.
// Mock only where the test API cannot produce the case: a paid amount that differs from the order.
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

const PAY_TIMEOUT = 300000;
const NOTICE = 'The old "Paynet Moldova Gateway" plugin is active';
const CONFIRMED = 'of the old plugin order';
const MANUAL = 'Check the payment manually in Paynet';

let ids;

const legacy = async ( request, active ) => ( await request.post( '/?rest_route=/paynet-e2e/v1/legacy', { data: { active } } ) ).json();
async function legacyOrder( request ) {
	const res = await request.post( '/?rest_route=/paynet-e2e/v1/legacy-order', { data: { product_id: ids.virtual } } );
	expect( res.ok(), 'old plugin order created: ' + ( await res.text() ).slice( 0, 200 ) ).toBeTruthy();
	const o = await res.json();
	expect( o.external, 'ExternalID in the v0.5 form' ).toMatch( new RegExp( '^' + o.id + '\\d{10}$' ) );
	return o;
}
const paynetByExternal = async ( request, ext ) => ( await request.get( '/?rest_route=/paynet-e2e/v1/paynet-external/' + ext ) ).json();

// Submit the old plugin's v0.5 form (as its receipt page does) — the browser goes to the Paynet test page.
async function openLegacyPayment( page, form ) {
	await page.goto( '/?paynet_e2e_blank=1' );
	await page.setContent( '<h2>Old plugin «Paynet Moldova Gateway» — payment form v0.5</h2>' + form );
	await Promise.all( [ page.waitForURL( /paynet\.md/, { timeout: 60000 } ), page.click( '#submit_paynet_button' ) ] );
}

async function waitPaidInPaynet( request, ext ) {
	return h.waitFor( async () => {
		const r = await paynetByExternal( request, ext );
		return r.code === 200 && r.data.status === 4 ? r.data : null;
	}, { timeout: 120000, message: 'payment status 4 in Paynet' } );
}

test.beforeEach( async ( { request } ) => {
	ids = ids || ( await h.setup( request ) );
	await h.setOptions( request, {
		paynet_wc_settings: {
			enabled: 'yes',
			mode: 'test',
			test_username: h.TEST_CREDS.username,
			test_password: h.TEST_CREDS.password,
			test_sale_area: h.TEST_CREDS.saleArea,
			test_notify_key: h.NOTIFY_KEY,
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
	const text = ( await page.locator( 'main, #wpbody-content, body' ).first().innerText().catch( () => '' ) ).replace( /\s+/g, ' ' );
	console.log( `--- DIAG ${ testInfo.title }\nURL: ${ page.url() }\nPAGE: ${ text.slice( 0, 1200 ) }` );
	const log = await h.log( request ).catch( () => ( { requests: [] } ) );
	console.log( 'API: ' + JSON.stringify( log.requests.slice( -8 ).map( ( r ) => r.method + ' ' + r.path + ' → ' + ( r.response || 'mock/none' ) ) ) );
} );

test.afterAll( async ( { request } ) => {
	await legacy( request, false ).catch( () => {} );
} );

test( '16 Old plugin active: warning in the admin', async ( { page, request } ) => {
	expect( ( await legacy( request, true ) ).active ).toBe( true );
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( '.notice-warning', { hasText: NOTICE } ) ).toBeVisible();
	await expect( page.locator( 'tr[data-plugin="paynet-gateway/paynet-gateway.php"]' ) ).toHaveClass( /\bactive\b/ );
	// Dashboard too (WooCommerce's own React pages, e.g. Settings → Payments, show no admin notices at all).
	await page.goto( '/wp-admin/index.php' );
	await expect( page.locator( '.notice-warning', { hasText: NOTICE } ) ).toBeVisible();
	await page.waitForTimeout( 1500 );

	expect( ( await legacy( request, false ) ).active ).toBe( false );
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( 'tr[data-plugin="paynet-gateway/paynet-gateway.php"]' ) ).not.toHaveClass( /\bactive\b/ );
	await expect( page.locator( '.notice-warning', { hasText: NOTICE } ) ).toHaveCount( 0 );
	await page.waitForTimeout( 1500 );
} );

test( '17 Old plugin order paid, customer returns by the old link — confirmed by the new plugin (В-3)', async ( { page, request } ) => {
	test.setTimeout( PAY_TIMEOUT );
	await legacy( request, true ); // Moment of the switch: both plugins active.
	const o = await legacyOrder( request );
	await openLegacyPayment( page, o.form );
	await h.payOnPaynet( page );
	// Paynet returns to the old link ?wc-api=WC_Gateway_Paynet&id=<ExternalID>&result=ok — handled by the new plugin.
	await page.waitForURL( /order-received/, { timeout: 120000 } );
	expect( page.url() ).toMatch( new RegExp( 'order-received[/=]' + o.id ) );
	await page.waitForTimeout( 1500 );

	const paid = await waitPaidInPaynet( request, o.external ); // В-3: the new API finds the v0.5 order.
	const s = await h.order( request, o.id );
	expect( s.status ).toBe( 'processing' );
	expect( s.transaction_id ).toBe( String( paid.paymentId ) );
	expect( s.meta._paynet_legacy_order ).toBe( o.external );
	expect( s.notes.filter( ( n ) => n.includes( CONFIRMED ) ) ).toHaveLength( 1 );
} );

test( '17 Old plugin order: notification after the old plugin is deactivated', async ( { page, request } ) => {
	test.setTimeout( PAY_TIMEOUT );
	await legacy( request, true );
	const o = await legacyOrder( request );
	// The customer pays but does not come back to the shop.
	await page.route( ( url ) => url.searchParams.get( 'wc-api' ) === 'WC_Gateway_Paynet' && url.searchParams.has( 'result' ), ( route ) => route.abort() );
	await openLegacyPayment( page, o.form );
	await h.payOnPaynet( page );
	const paid = await waitPaidInPaynet( request, o.external );
	await page.unrouteAll( { behavior: 'ignoreErrors' } );

	await legacy( request, false ); // The merchant has switched to the new plugin.
	expect( ( await h.order( request, o.id ) ).status ).toBe( 'pending' );

	const n = h.notification( { paymentId: paid.paymentId, externalId: o.external, amount: o.amount } );
	const r1 = await h.sendNotification( request, n );
	expect( r1.status ).toBe( 200 );
	expect( r1.body ).toContain( 'SUCCESS' );
	const r2 = await h.sendNotification( request, n ); // Repeated notification.
	expect( r2.status ).toBe( 200 );

	const s = await h.order( request, o.id );
	expect( s.status ).toBe( 'processing' );
	expect( s.transaction_id ).toBe( String( paid.paymentId ) );
	expect( s.notes.filter( ( n2 ) => n2.includes( CONFIRMED ) ) ).toHaveLength( 1 );

	// Admin sees the order paid, with the note of the new plugin.
	const { hpos } = await h.helperState( request );
	await page.goto( hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ o.id }` : `/wp-admin/post.php?post=${ o.id }&action=edit` );
	await expect( page.locator( '.order_notes' ) ).toContainText( CONFIRMED );
	await page.waitForTimeout( 2000 );
} );

test( '17 Old plugin order not found in Paynet: note «check manually», old cancel link does not cancel', async ( { page, request } ) => {
	await legacy( request, true );
	const o = await legacyOrder( request ); // Form never submitted: Paynet does not know this ExternalID.
	await legacy( request, false );

	const n = h.notification( { paymentId: 1, externalId: o.external, amount: o.amount } );
	const r = await h.sendNotification( request, n );
	expect( r.status ).toBe( 200 );
	let s = await h.order( request, o.id );
	expect( s.status ).toBe( 'pending' );
	expect( s.notes.filter( ( x ) => x.includes( MANUAL ) ) ).toHaveLength( 1 );

	// Old return link with result=error: the old plugin cancelled the order here; the new one only checks.
	await page.goto( `/?wc-api=WC_Gateway_Paynet&id=${ o.external }&result=error` );
	await expect( page ).not.toHaveURL( /order-received/ );
	await page.waitForTimeout( 1500 );
	s = await h.order( request, o.id );
	expect( s.status ).toBe( 'pending' );
	expect( s.notes.filter( ( x ) => x.includes( MANUAL ) ) ).toHaveLength( 1 ); // Noted once per ExternalID.

	// Forged notification with a wrong Hash: 400, nothing changes.
	const bad = await h.sendNotification( request, n, 'AAAAAAAAAAAAAAAAAAAAAA==' );
	expect( bad.status ).toBe( 400 );
} );

test( '17 Notification for an unknown Order (e.g. another site on the same SaleArea): 200, no order touched', async ( { request } ) => {
	await legacy( request, true );
	const o = await legacyOrder( request ); // The latest order of the shop.
	await legacy( request, false );
	const before = await h.order( request, o.id );
	// Signed, but neither a new-plugin Order nor an old-plugin order of this shop.
	const r = await h.sendNotification( request, h.notification( { paymentId: 5, externalId: '987654321012345678', amount: o.amount } ) );
	expect( r.status ).toBe( 200 );
	const after = await h.order( request, o.id );
	expect( after.status ).toBe( before.status );
	expect( after.notes ).toEqual( before.notes );
	expect( after.meta ).toEqual( before.meta );
} );

test( '17 Old plugin order: paid amount differs from the order (mock) — on-hold, admin email', async ( { request } ) => {
	await legacy( request, true );
	const o = await legacyOrder( request );
	await legacy( request, false );
	await h.setMocks( request, [
		{ method: 'GET', path: '/api/order/merchant/' + o.external, status: 200, body: h.paidStatus( { paymentId: 777001, order: o.external, amount: o.amount - 100, paidAmount: o.amount - 100 } ) },
	] );
	const r = await h.sendNotification( request, h.notification( { paymentId: 777001, externalId: o.external, amount: o.amount } ) );
	expect( r.status ).toBe( 200 );
	const s = await h.order( request, o.id );
	expect( s.status ).toBe( 'on-hold' );
	expect( s.notes.join( ' ' ) ).toContain( 'differs from the order total' );
	const log = await h.log( request );
	expect( log.mails.some( ( m ) => m.subject.includes( '#' + o.id ) ) ).toBe( true );
} );

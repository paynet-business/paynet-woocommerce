// Stage 6 — translations RU/RO, payment method icons, uninstall, screenshots for WordPress.org (ТЗ разд. 15, этап 6).
// Titles start with S6-n (stage checks outside the ТЗ scenario list, as S2-n in stage 2).
// Screenshots for the plugin directory are written to results/wporg/ (CI artifact "wporg-screenshots").
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );
const h = require( './helpers' );

let ids;
const SETTINGS = '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=paynet_wc';
const WPORG = path.join( __dirname, '..', 'results', 'wporg' );
// The test's own page (recorded in the stage video) without the admin session.
const asGuest = async ( page ) => {
	await page.context().clearCookies();
};
const shot = async ( page, n, locator ) => {
	fs.mkdirSync( WPORG, { recursive: true } );
	await ( locator || page ).screenshot( { path: path.join( WPORG, `screenshot-${ n }.png` ) } );
};

const BASE = () => ( {
	enabled: 'yes',
	mode: 'test',
	test_username: h.TEST_CREDS.username,
	test_password: h.TEST_CREDS.password,
	test_sale_area: h.TEST_CREDS.saleArea,
	test_notify_key: h.NOTIFY_KEY,
	debug: 'yes',
	saved_cards: 'yes',
	icons: [ 'visa', 'mastercard', 'applepay', 'googlepay' ],
	delete_on_uninstall: 'no',
	title: 'Card, Apple Pay, Google Pay (Paynet)',
	description: 'You will be redirected to the secure Paynet payment page.',
} );

test.beforeEach( async ( { request } ) => {
	ids = ids || ( await h.setup( request ) );
	await h.setOptions( request, { paynet_wc_settings: BASE(), WPLANG: '', woocommerce_checkout_page_id: ids.classic_checkout } );
} );

test.afterAll( async ( { request } ) => {
	await h.setOptions( request, { WPLANG: '', paynet_wc_settings: BASE() } ).catch( () => {} );
} );

// Site language → settings, checkout (classic + Blocks) and an order note written while creating the payment.
const LANGS = [
	{
		n: 1, locale: 'ru_RU', name: 'Russian',
		settings: [ 'Включить Paynet', 'ТЕСТОВЫЙ РЕЖИМ', 'Проверить подключение', 'Удалять данные плагина при его удалении' ],
		title: 'Карта, Apple Pay, Google Pay (Paynet)',
		description: 'ТЕСТОВЫЙ РЕЖИМ: реальные деньги не списываются. Вы перейдёте на защищённую страницу оплаты Paynet.',
		note: /Создан платёж Paynet #\d+, Order \d+ \(режим: тест\)/,
	},
	{
		n: 2, locale: 'ro_RO', name: 'Romanian',
		settings: [ 'Activează Paynet', 'MOD DE TEST', 'Verifică conexiunea', 'Șterge datele modulului la ștergerea lui' ],
		title: 'Card, Apple Pay, Google Pay (Paynet)',
		description: 'MOD DE TEST: nu se percep bani reali. Veți fi redirecționat către pagina securizată de plată Paynet.',
		note: /Plata Paynet #\d+ a fost creată, Order \d+ \(mod: test\)/,
	},
];

for ( const L of LANGS ) {
	test( `S6-${ L.n } ${ L.name } site language: settings, checkout and order notes`, async ( { page, request } ) => {
		test.setTimeout( 180000 );
		// Title / description left at the English defaults follow the site language ([Пр6-6]).
		await h.setOptions( request, { WPLANG: L.locale, paynet_wc_settings: { title: 'Card, Apple Pay, Google Pay (Paynet)', description: 'You will be redirected to the secure Paynet payment page.' } } );

		await page.goto( SETTINGS );
		for ( const text of L.settings ) {
			await expect( page.getByText( text ).first(), text ).toBeVisible();
		}

		await asGuest( page );
		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.blocks_checkout );
		await page.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
		await expect( page.getByText( L.title ).first() ).toBeVisible();
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await expect( page.locator( 'li.payment_method_paynet_wc' ) ).toContainText( L.title );
		await page.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		await expect( page.locator( '.payment_box.payment_method_paynet_wc' ) ).toContainText( L.description );
		await h.classicPlaceOrder( page, ids, { email: `e2e-${ L.locale }@example.com` } );
		await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
		const o = await h.order( request, await h.latestOrder( request ) );
		expect( o.notes.join( '\n' ) ).toMatch( L.note );

		// A title the merchant changed stays as entered.
		await h.setOptions( request, { paynet_wc_settings: { title: 'Plată online (Paynet)' } } );
		await page.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( page );
		await expect( page.locator( 'li.payment_method_paynet_wc' ) ).toContainText( 'Plată online (Paynet)' );
	} );
}

test( 'S6-3 Payment method icons: classic and Blocks, selection in the settings', async ( { page, request } ) => {
	test.setTimeout( 180000 );
	const probe = await ( await request.get( '/?rest_route=/paynet-e2e/v1/wc-icons' ) ).json();
	test.info().annotations.push( { type: 'WooCommerce icons', description: `WC ${ probe.wc }: ${ probe.files.join( ', ' ) }` } );
	console.log( 'WooCommerce icons:', JSON.stringify( probe ) );

	await asGuest( page );
	const guest = page;
	const loaded = ( loc ) => loc.evaluateAll( ( imgs ) => imgs.map( ( i ) => ( { alt: i.alt, ok: i.complete && i.naturalWidth > 0 } ) ) );
	{
		await h.addToCart( guest, ids.product );

		// Classic checkout.
		await guest.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( guest );
		const classic = guest.locator( 'li.payment_method_paynet_wc img.paynet-wc-icon' );
		await expect( classic.first() ).toBeVisible();
		const c = await loaded( classic );
		expect( c.map( ( i ) => i.alt ) ).toEqual( [ 'Visa', 'Mastercard', 'Apple Pay', 'Google Pay' ] );
		expect( c.every( ( i ) => i.ok ), JSON.stringify( c ) ).toBeTruthy();
		await guest.locator( '#payment_method_paynet_wc' ).check().catch( () => {} );
		await shot( guest, 2, guest.locator( '#payment' ) );

		// Blocks checkout.
		await guest.goto( '/?page_id=' + ids.blocks_checkout );
		await guest.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
		const blocks = guest.locator( '.paynet-wc-label img.paynet-wc-icon' );
		await expect( blocks.first() ).toBeVisible();
		const b = await loaded( blocks );
		expect( b.map( ( i ) => i.alt ) ).toEqual( c.map( ( i ) => i.alt ) );
		expect( b.every( ( i ) => i.ok ) ).toBeTruthy();

		// Only Visa selected → one icon; none selected → no icons, the title stays.
		await h.setOptions( request, { paynet_wc_settings: { icons: [ 'visa' ] } } );
		await guest.goto( '/?page_id=' + ids.classic_checkout );
		await h.waitUnblocked( guest );
		await expect( guest.locator( 'li.payment_method_paynet_wc img.paynet-wc-icon' ) ).toHaveCount( 1 );
		await expect( guest.locator( 'li.payment_method_paynet_wc img.paynet-wc-icon' ) ).toHaveAttribute( 'alt', 'Visa' );
		await h.setOptions( request, { paynet_wc_settings: { icons: '' } } );
		await guest.goto( '/?page_id=' + ids.blocks_checkout );
		await guest.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
		await expect( guest.getByText( 'Card, Apple Pay, Google Pay (Paynet)' ).first() ).toBeVisible();
		await expect( guest.locator( '.paynet-wc-label img' ) ).toHaveCount( 0 );
	}
} );

test( 'S6-4 Uninstall: without "Delete plugin data" only background checks go; with it settings and saved cards go, orders stay', async ( { request } ) => {
	const call = async ( action ) => ( await request.post( '/?rest_route=/paynet-e2e/v1/uninstall/' + action ) ).json();
	const c = await h.createOrder( request, { product_id: ids.virtual } );
	await h.setMeta( request, c.id, { _paynet_order: String( 950000000000 + c.id ) } );
	await h.clearCards( request, ids.customer );
	await h.addCard( request, ids.customer, { hash: 'e2e-uninstall-1', last4: '4242' } );
	await h.addCard( request, ids.customer, { hash: 'e2e-uninstall-2', last4: '5454', type: 'mastercard' } );
	await h.helperState( request ); // Creates the test salt if missing.

	await call( 'fixture' );
	let s = await call( 'state' );
	expect( s ).toMatchObject( { settings: true, transient: true, tokens: 2 } );
	expect( s.scheduled ).toBeGreaterThan( 0 );

	s = await call( 'run' );
	expect( s, 'option off: data kept, checks unscheduled' ).toMatchObject( { settings: true, transient: true, tokens: 2, scheduled: 0 } );

	await h.setOptions( request, { paynet_wc_settings: { delete_on_uninstall: 'yes' } } );
	await call( 'fixture' );
	s = await call( 'run' );
	expect( s, 'option on: all plugin data deleted' ).toMatchObject( { settings: false, salt: false, transient: false, tokens: 0, scheduled: 0 } );

	const o = await h.order( request, c.id );
	expect( JSON.stringify( o ), 'order meta stays' ).toContain( String( 950000000000 + c.id ) );
} );

test( 'S6-5 Screenshots for the plugin directory (settings, saved cards, My account)', async ( { page, request } ) => {
	await page.goto( SETTINGS );
	await expect( page.locator( '#woocommerce_paynet_wc_icons' ) ).toHaveCount( 1 );
	await page.waitForLoadState( 'networkidle' ).catch( () => {} );
	await shot( page, 1, page.locator( '#wpbody-content' ) );

	await h.clearCards( request, ids.customer );
	await h.addCard( request, ids.customer, { hash: 'e2e-shot-1', last4: '4242', month: '12', year: '2029' } );
	await h.addCard( request, ids.customer, { hash: 'e2e-shot-2', last4: '5454', type: 'mastercard', month: '03', year: '2030' } );
	try {
		await asGuest( page );
		await h.login( page );
		await h.addToCart( page, ids.product );
		await page.goto( '/?page_id=' + ids.blocks_checkout );
		await page.waitForSelector( '.paynet-wc-cards', { timeout: 30000 } );
		const pm = page.locator( '.wc-block-checkout__payment-method' );
		await shot( page, 3, ( await pm.count() ) ? pm.first() : null );
		const state = await h.helperState( request );
		await page.goto( state.payment_methods );
		await expect( page.getByText( '4242' ).first() ).toBeVisible();
		await shot( page, 4, page.locator( '.woocommerce' ).first() );
	} finally {
		await h.clearCards( request, ids.customer );
	}
	const files = fs.readdirSync( WPORG ).sort();
	test.info().annotations.push( { type: 'screenshots', description: files.join( ', ' ) } );
	expect( files ).toEqual( expect.arrayContaining( [ 'screenshot-1.png', 'screenshot-2.png', 'screenshot-3.png', 'screenshot-4.png' ] ) );
} );

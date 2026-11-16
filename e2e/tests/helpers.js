// Shared helpers for stage 3+ E2E tests.
const crypto = require( 'crypto' );
const { expect } = require( '@playwright/test' );

const CARD = { number: '4111111111111111', month: '12', year: '27', cvv: '777' };
const NOTIFY_KEY = 'e2e-notification-key-1234';
const API = 'https://ecom-api-test.paynet.md';
const TEST_CREDS = { username: '771064', password: 'sLgMnGWJ', saleArea: 'onlidocumentation_md' };

const rest = ( path ) => '/?rest_route=/paynet-e2e/v1' + path;

async function helper( request, method, path, data ) {
	const res = await request.fetch( rest( path ), { method, data, headers: { 'Content-Type': 'application/json' } } );
	const text = await res.text();
	if ( ! res.ok() ) {
		throw new Error( `${ method } ${ path } → ${ res.status() } ${ text.slice( 0, 300 ) }` );
	}
	return JSON.parse( text );
}

const setup = ( request ) => helper( request, 'POST', '/setup', {} );
const order = ( request, id ) => helper( request, 'GET', `/order/${ id }` );
const latestOrder = async ( request ) => ( await helper( request, 'GET', '/latest-order' ) ).id;
const runChecks = ( request, id ) => helper( request, 'POST', `/run-checks/${ id }` );
const setMocks = ( request, mocks ) => helper( request, 'POST', '/mocks', mocks );
const setOptions = ( request, options ) => helper( request, 'POST', '/options', options );
const setMeta = ( request, id, meta ) => helper( request, 'POST', `/order/${ id }/meta`, meta );
const log = ( request ) => helper( request, 'GET', '/log' );
const createOrder = ( request, data ) => helper( request, 'POST', '/create-order', data );
const helperState = ( request ) => helper( request, 'GET', '/state' );

function chisinau( minutesAhead ) {
	const parts = Object.fromEntries( new Intl.DateTimeFormat( 'en-GB', {
		timeZone: 'Europe/Chisinau', year: 'numeric', month: '2-digit', day: '2-digit',
		hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
	} ).formatToParts( new Date( Date.now() + minutesAhead * 60000 ) ).map( ( p ) => [ p.type, p.value ] ) );
	return `${ parts.year }-${ parts.month }-${ parts.day }T${ parts.hour }:${ parts.minute }:${ parts.second }`;
}

// Another user of the shared test account takes the same Paynet Order with other data (ТЗ scenario 23).
async function occupyOrder( salt, orderId, attempt ) {
	const number = ( BigInt( salt ) * 1000000000000n + BigInt( orderId ) * 1000n + BigInt( attempt ) ).toString();
	const auth = await ( await fetch( API + '/auth', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { grantType: 'password', username: TEST_CREDS.username, password: TEST_CREDS.password } ),
	} ) ).json();
	const body = JSON.stringify( {
		Order: '__ORDER__',
		SaleAreaCode: TEST_CREDS.saleArea,
		LinkUrlSuccess: 'https://other-user.example/ok',
		LinkUrlCancel: 'https://other-user.example/cancel',
		Currency: 498,
		ExpiryDate: chisinau( 60 ),
		Services: [ { Name: 'Other user', Description: 'Other order', Amount: 100 } ],
		MoneyType: 'All',
		Lang: 'EN',
	} ).replace( '"__ORDER__"', number );
	const res = await fetch( API + '/api/order', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + auth.access_token }, body } );
	if ( 200 !== res.status ) {
		throw new Error( 'Could not occupy Order ' + number + ': ' + res.status + ' ' + ( await res.text() ).slice( 0, 200 ) );
	}
	return number;
}

// Notification exactly as Paynet sends it: ExternalId as a JSON number (int64), Hash header.
function notification( { paymentId, externalId, amount, paidAmount, key = NOTIFY_KEY, moneyType = 'CARD' } ) {
	const eventDate = new Date().toISOString().slice( 0, 19 );
	const eventId = Math.floor( Math.random() * 1e9 );
	const payment = {
		Id: Number( paymentId ),
		ExternalId: '__EXT__',
		SaleAreaCode: TEST_CREDS.saleArea,
		Customer: 'e2eXXX',
		StatusDate: eventDate,
		Amount: amount,
		PaidAmount: paidAmount === undefined ? amount : paidAmount,
		Merchant: '265505',
		CardMask: '411111******1111',
		CardExpireMonth: '12',
		CardExpireYear: '27',
		CardIssuer: 'TEST BANK',
		CardLinkHash: null,
		MoneyType: moneyType,
		PayerName: null,
		PayerSurname: null,
		PayerIDNP: null,
		RRN: '000000000000',
	};
	const body = JSON.stringify( { Eventid: eventId, EventType: 'PAID', Message: 'Request processed successfully', EventDate: eventDate, Payment: payment } )
		.replace( '"__EXT__"', String( externalId ) );
	const prepared = eventDate + eventId + 'PAID' + amount + 'e2eXXX' + externalId + paymentId + '265505' + eventDate;
	const hash = crypto.createHash( 'md5' ).update( prepared + key, 'utf8' ).digest( 'base64' );
	return { body, hash };
}

async function sendNotification( request, n, hash ) {
	const res = await request.post( '/?wc-api=WC_Gateway_Paynet', {
		data: n.body,
		headers: { 'Content-Type': 'application/json; charset=utf-8', Hash: hash || n.hash },
		maxRedirects: 0,
	} );
	return { status: res.status(), body: await res.text() };
}

// Fill the Paynet test payment page (card fields in Cybersource iframes) and press Pay — same as the docs portal E2E.
async function payOnPaynet( page ) {
	await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
	await page.waitForLoadState( 'networkidle' ).catch( () => {} );
	await page.waitForTimeout( 2500 );
	let numberFrame;
	let cvvFrame;
	for ( let i = 0; i < 20 && ( ! numberFrame || ! cvvFrame ); i++ ) {
		numberFrame = page.frames().find( ( f ) => f.url().includes( 'microform' ) && f.url().includes( '%22fieldType%22:%22number%22' ) );
		cvvFrame = page.frames().find( ( f ) => f.url().includes( 'microform' ) && f.url().includes( 'securityCode' ) );
		if ( ! numberFrame || ! cvvFrame ) {
			await page.waitForTimeout( 500 );
		}
	}
	expect( numberFrame, 'card number field on the Paynet page' ).toBeTruthy();
	await numberFrame.fill( '#number', CARD.number );
	await cvvFrame.fill( '#securityCode', CARD.cvv );
	await page.click( '#CardExp' );
	await page.keyboard.type( CARD.month + CARD.year, { delay: 80 } );
	await page.waitForTimeout( 800 );
	await page.click( '#submit-default' );
}

// Customer does not come back to the shop after paying (closed the tab): block the return URL.
async function blockReturn( page ) {
	let returned = null;
	await page.route( ( url ) => url.href.includes( 'paynet_return=' ), ( route ) => {
		returned = route.request().url();
		return route.abort();
	} );
	return () => returned;
}

async function waitFor( fn, { timeout = 90000, interval = 1500, message = 'condition' } = {} ) {
	const start = Date.now();
	let last;
	while ( Date.now() - start < timeout ) {
		last = await fn();
		if ( last ) {
			return last;
		}
		await new Promise( ( r ) => setTimeout( r, interval ) );
	}
	throw new Error( 'Timed out waiting for ' + message );
}

async function fillIfVisible( page, selector, value ) {
	const el = page.locator( selector ).first();
	if ( ( await el.count() ) && ( await el.isVisible() ) ) {
		await el.fill( value );
		return true;
	}
	return false;
}

async function waitUnblocked( page ) {
	await page.waitForTimeout( 500 );
	await page.waitForFunction( () => ! document.querySelector( '.blockUI.blockOverlay' ), null, { timeout: 30000 } ).catch( () => {} );
}

// Classic checkout ([woocommerce_checkout]) as a guest or a logged-in customer.
async function classicPlaceOrder( page, ids, opts = {} ) {
	const { first = 'Ion', last = 'Popescu', email = 'e2e-guest@example.com', coupon = null } = opts;
	await page.goto( '/?page_id=' + ids.classic_checkout );
	await waitUnblocked( page );
	if ( coupon ) {
		await page.click( '.showcoupon' );
		await page.fill( '#coupon_code', coupon );
		await page.click( 'button[name="apply_coupon"]' );
		await waitUnblocked( page );
		await expect( page.locator( '.cart-discount' ) ).toBeVisible();
	}
	await fillIfVisible( page, '#billing_first_name', first );
	await fillIfVisible( page, '#billing_last_name', last );
	await fillIfVisible( page, '#billing_address_1', 'Str. Test 1' );
	await fillIfVisible( page, '#billing_city', 'Chisinau' );
	await fillIfVisible( page, '#billing_postcode', '2001' );
	await fillIfVisible( page, '#billing_phone', '+373 60 000 000' );
	await fillIfVisible( page, '#billing_email', email );
	await waitUnblocked( page );
	const radio = page.locator( '#payment_method_paynet_wc' );
	if ( await radio.isVisible() ) {
		await radio.check();
	}
	await expect( page.locator( '.payment_method_paynet_wc' ) ).toContainText( 'Paynet' );
	await waitUnblocked( page );
	await page.click( '#place_order' );
}

// Block checkout (WooCommerce Cart & Checkout blocks).
async function blocksPlaceOrder( page, ids, { email = 'e2e-blocks@example.com' } = {} ) {
	await page.goto( '/?page_id=' + ids.blocks_checkout );
	await page.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
	await fillIfVisible( page, '#email', email );
	for ( const prefix of [ 'shipping', 'billing' ] ) {
		await fillIfVisible( page, `#${ prefix }-first_name`, 'Ion' );
		await fillIfVisible( page, `#${ prefix }-last_name`, 'Popescu' );
		await fillIfVisible( page, `#${ prefix }-address_1`, 'Str. Test 1' );
		await fillIfVisible( page, `#${ prefix }-city`, 'Chisinau' );
		await fillIfVisible( page, `#${ prefix }-postcode`, '2001' );
		await fillIfVisible( page, `#${ prefix }-phone`, '37360000000' );
	}
	await expect( page.getByText( 'Card, Apple Pay, Google Pay (Paynet)' ).first() ).toBeVisible();
	const radio = page.locator( '#radio-control-wc-payment-method-options-paynet_wc' );
	if ( ( await radio.count() ) && ( await radio.isVisible() ) ) {
		await radio.check();
	}
	await page.waitForTimeout( 1000 );
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
}

async function addToCart( page, productId, qty = 1 ) {
	await page.goto( `/?add-to-cart=${ productId }&quantity=${ qty }` );
}

async function login( page, user = 'e2e-customer', pass = 'e2e-customer-pass' ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

// Paid status object as GET /api/order/merchant/{Order} returns it (shape from the docs portal mocks).
function paidStatus( { paymentId, order, amount, paidAmount, status = 4 } ) {
	return {
		paymentId: Number( paymentId ),
		paymentLink: 'https://test.paynet.md/ecom/getecom',
		cardLinkHash: null,
		status,
		message: null,
		order: Number( order ),
		merchantCode: '265505',
		saleAreaCode: TEST_CREDS.saleArea,
		amount: { requestAmount: amount, requestCurrency: 498, amount, paidAmount: status === 4 ? paidAmount : null },
		customer: null,
		payer: null,
		expiryDate: '2030-01-01 00:00:00',
		registered: '2026-11-16 12:00:00',
		paidDate: status === 4 ? '2026-11-16 12:01:00' : null,
		services: [],
		moneyType: 'All',
	};
}

// Values passed between scenarios (order IDs), persisted in results/shared.json.
function sharedState() {
	const fs = require( 'fs' );
	const file = require( 'path' ).join( __dirname, '..', 'results', 'shared.json' );
	const read = () => {
		try {
			return JSON.parse( fs.readFileSync( file, 'utf8' ) );
		} catch ( e ) {
			return {};
		}
	};
	return new Proxy( {}, {
		get: ( t, k ) => read()[ k ],
		set: ( t, k, v ) => {
			const d = read();
			d[ k ] = v;
			fs.writeFileSync( file, JSON.stringify( d ) );
			return true;
		},
	} );
}

module.exports = {
	sharedState,
	API, TEST_CREDS, NOTIFY_KEY, setup, helperState, occupyOrder, order, latestOrder, runChecks, setMocks, setOptions, setMeta, log, createOrder,
	notification, sendNotification, payOnPaynet, blockReturn, waitFor, classicPlaceOrder, blocksPlaceOrder, addToCart, login, paidStatus, waitUnblocked,
};

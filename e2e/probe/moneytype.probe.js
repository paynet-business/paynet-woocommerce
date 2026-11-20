// Probe of the Paynet TEST API: which MoneyType values POST /api/order accepts and what the
// Paynet page then shows (design of "payment methods on the site", 20.11.2026).
// Test sale area has only cards and MIA enabled. Findings go to the job log as "--- DIAG" lines.
'use strict';

const { test } = require( '@playwright/test' );

const BASE = 'https://ecom-api-test.paynet.md';
const USER = '771064';
const PASS = 'sLgMnGWJ';
const SA = 'onlidocumentation_md';
const runBase = Date.now() * 1000;
let seq = 0;
let token = null;

const log = ( label, data ) => console.log( '--- DIAG ' + label + ' ' + ( typeof data === 'string' ? data : JSON.stringify( data ) ) );

function chisinau( minutesAhead ) {
	const d = new Date( Date.now() + minutesAhead * 60000 );
	const p = new Intl.DateTimeFormat( 'en-GB', {
		timeZone: 'Europe/Chisinau', year: 'numeric', month: '2-digit', day: '2-digit',
		hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
	} ).formatToParts( d ).reduce( ( a, x ) => ( ( a[ x.type ] = x.value ), a ), {} );
	return `${ p.year }-${ p.month }-${ p.day }T${ p.hour }:${ p.minute }:${ p.second }`;
}

async function auth() {
	const r = await fetch( BASE + '/auth', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { grantType: 'password', username: USER, password: PASS } ),
	} );
	token = ( await r.json() ).access_token;
}

async function call( method, path, body ) {
	const r = await fetch( BASE + path, {
		method,
		headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
		body: body ? JSON.stringify( body ) : undefined,
	} );
	const text = await r.text();
	let json = null;
	try {
		json = JSON.parse( text );
	} catch ( e ) {}
	return { status: r.status, json, text: text.slice( 0, 500 ) };
}

async function create( moneyType ) {
	seq += 1;
	const order = runBase + seq;
	const body = {
		Order: order,
		SaleAreaCode: SA,
		LinkUrlSuccess: 'https://shop.example/success',
		LinkUrlCancel: 'https://shop.example/cancel',
		Customer: { Code: 'mt-probe', NameFirst: 'Ion', NameLast: 'Popescu', email: 'ion@example.com', PhoneNumber: '37360000000' },
		Currency: 498,
		ExpiryDate: chisinau( 60 ),
		Services: [ { Name: 'Probe shop', Description: 'MoneyType probe ' + seq, Amount: 300, Products: [ { LineNo: 1, Code: 'P', Name: 'Probe', UnitPrice: 300, Quantity: 100, Amount: 300, TotalAmount: 300 } ] } ],
		MoneyType: moneyType,
		Lang: 'EN',
	};
	const r = await call( 'POST', '/api/order', body );
	return { order, r };
}

// Visible text of the page and all its frames, plus markers of the payment methods.
async function describePage( page ) {
	await page.waitForLoadState( 'networkidle', { timeout: 30000 } ).catch( () => {} );
	await page.waitForTimeout( 4000 );
	const texts = [];
	const markers = { cardInput: 0, qrImage: 0, frames: page.frames().length };
	for ( const f of page.frames() ) {
		const t = await f.evaluate( () => document.body ? document.body.innerText : '' ).catch( () => '' );
		if ( t ) {
			texts.push( t.replace( /\s+/g, ' ' ).trim() );
		}
		markers.cardInput += await f.locator( 'input[autocomplete="cc-number"], input[name*="card" i], input[id*="card" i], input[placeholder*="card" i]' ).count().catch( () => 0 );
		markers.qrImage += await f.locator( 'img[src*="qr" i], img[alt*="qr" i], canvas, svg[class*="qr" i]' ).count().catch( () => 0 );
	}
	const all = texts.join( ' | ' );
	const words = [ 'MIA', 'QR', 'Card', 'Apple Pay', 'Google Pay', 'Paynet', 'Cash', 'Invoice', 'Pay NOW', 'error', 'not available', 'Error' ]
		.filter( ( w ) => all.toLowerCase().includes( w.toLowerCase() ) );
	return { url: page.url().slice( 0, 120 ), words, markers, text: all.slice( 0, 700 ) };
}

test.describe.configure( { mode: 'serial' } );

test( 'MoneyType values in POST /api/order and the Paynet page', async ( { page } ) => {
	test.setTimeout( 15 * 60 * 1000 );
	await auth();
	const values = [
		'All', 'Card', 'CARD', 'card',
		'MIA_QR', 'MIA', 'MiaQr', 'QR',
		'APPLEPAY', 'GOOGLEPAY', 'ApplePay', 'GooglePay', 'WALLET',
		'PAYNET', 'Paynet', 'CASH_INVOICE', 'CashInvoice',
		'XYZ', '', 'CARD,MIA_QR', [ 'CARD', 'MIA_QR' ],
	];
	for ( const v of values ) {
		const { order, r } = await create( v );
		const res = { status: r.status, code: r.json?.code, message: r.json?.message, details: r.json?.details, paymentId: r.json?.paymentId };
		if ( r.status !== 200 ) {
			res.raw = r.text;
		}
		log( 'create MoneyType=' + JSON.stringify( v ), res );
		if ( r.status === 200 && r.json?.paymentLink ) {
			const g = await call( 'GET', '/api/order/merchant/' + order );
			log( '  GET moneyType=' + JSON.stringify( v ), { status: g.status, moneyType: g.json?.moneyType, payer: g.json?.payer?.moneyType ?? null } );
			await page.goto( r.json.paymentLink, { timeout: 60000 } ).catch( ( e ) => log( '  goto error', String( e ).slice( 0, 200 ) ) );
			log( '  page MoneyType=' + JSON.stringify( v ), await describePage( page ) );
		}
	}
} );

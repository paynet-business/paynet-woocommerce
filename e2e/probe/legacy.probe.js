// Probe of ТЗ В-3 (stage 5): does the new Ecom API find orders created by the old plugin (protocol v0.5)?
// Creates an order exactly like "Paynet Moldova Gateway" does (POST form to /Acquiring/SetEcom, MD5 signature),
// pays it with the test card, then asks GET /api/order/merchant/{ExternalID} of the new API.
// Every finding is printed to the job log as "--- DIAG" lines. No WordPress involved.
'use strict';

const crypto = require( 'crypto' );
const { test } = require( '@playwright/test' );

const BASE = 'https://ecom-api-test.paynet.md';
const OLD_API = 'https://api-merchant.test.paynet.md';
const FORM = 'https://test.paynet.md/Acquiring/SetEcom';
const USER = '771064';
const PASS = 'sLgMnGWJ';
const MERCHANT = '265505';
const SA = 'onlidocumentation_md';
// Which key signs the v0.5 form is unknown: try the form key of the new docs, then the notification key.
const KEYS = { form: 'F90D6485-9CCC-458B-96A3-8958AF9B4458', notify: 'D9873689-E5F8-4272-A67F-0D8BA3F9BAA7' };
const CARD = { number: '4111111111111111', month: '12', year: '27', cvv: '777' };

const log = ( label, data ) => console.log( '--- DIAG ' + label + ' ' + ( typeof data === 'string' ? data : JSON.stringify( data ) ) );
let token = null;

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

async function getNew( order ) {
	const r = await fetch( BASE + '/api/order/merchant/' + order, { headers: { Authorization: 'Bearer ' + token } } );
	const text = await r.text();
	let json = null;
	try {
		json = JSON.parse( text );
	} catch ( e ) {}
	return { status: r.status, json, text: text.slice( 0, 1500 ) };
}

// Old merchant API, exactly as the old plugin calls it (form-encoded /auth, then /api/Payments?ExternalID=).
async function getOld( external ) {
	try {
		const a = await fetch( OLD_API + '/auth', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'grant_type=password&username=' + USER + '&password=' + encodeURIComponent( PASS ),
		} );
		const at = await a.text();
		let t = null;
		try {
			t = JSON.parse( at ).access_token;
		} catch ( e ) {}
		if ( ! t ) {
			return { auth: a.status, body: at.slice( 0, 300 ) };
		}
		const r = await fetch( OLD_API + '/api/Payments?ExternalID=' + external, { headers: { Authorization: 'bearer ' + t } } );
		return { auth: a.status, status: r.status, body: ( await r.text() ).slice( 0, 800 ) };
	} catch ( e ) {
		return { error: String( e ).slice( 0, 200 ) };
	}
}

// v0.5 form of the old plugin (one product line), signed the same way.
function legacyForm( external, key ) {
	const amount = 300;
	const f = {
		ExternalID: String( external ),
		Merchant: MERCHANT,
		SaleAreaCode: SA,
		Currency: '498',
		'Services[0][Name]': 'Probe shop',
		'Services[0][Description]': 'Probe shop',
		'Services[0][Amount]': String( amount ),
		'Services[0][Products][0][Amount]': String( amount ),
		'Services[0][Products][0][Barcode]': '11',
		'Services[0][Products][0][Code]': '11',
		'Services[0][Products][0][Description]': 'Probe item',
		'Services[0][Products][0][GroupId]': '15',
		'Services[0][Products][0][GroupName]': 'Uncategorized',
		'Services[0][Products][0][LineNo]': '0',
		'Services[0][Products][0][Name]': 'Probe item',
		'Services[0][Products][0][Quantity]': '1',
		'Services[0][Products][0][UnitPrice]': String( amount ),
		'Services[0][Products][0][UnitProduct]': 'pcs',
		'Customer.Code': '1',
		'Customer.NameFirst': 'Ion',
		'Customer.NameLast': 'Popescu',
		'Customer.PhoneNumber': '37360000000',
		'Customer.email': 'ion@example.com',
		'Customer.Country': 'Moldova',
		'Customer.City': 'Chisinau',
		'Customer.Address': 'Str 1',
		ExternalDate: chisinau( 0 ),
		ExpiryDate: chisinau( 120 ),
		LinkUrlSuccess: 'https://shop.example/?wc-api=WC_Gateway_Paynet&id=' + external + '&result=ok',
		LinkUrlCancel: 'https://shop.example/?wc-api=WC_Gateway_Paynet&id=' + external + '&result=error',
		Lang: 'en',
		SignVersion: 'v05',
		'MoneyType.Code': '',
	};
	const p = ( n ) => f[ 'Services[0][Products][0][' + n + ']' ];
	const raw = f.Currency +
		f[ 'Customer.Address' ] + f[ 'Customer.City' ] + f[ 'Customer.Code' ] + f[ 'Customer.Country' ] + f[ 'Customer.email' ] +
		f[ 'Customer.NameFirst' ] + f[ 'Customer.NameLast' ] + f[ 'Customer.PhoneNumber' ] +
		f.ExpiryDate + f.ExternalID + f.Merchant + f[ 'MoneyType.Code' ] +
		f[ 'Services[0][Amount]' ] + f[ 'Services[0][Description]' ] + f[ 'Services[0][Name]' ] +
		p( 'Amount' ) + p( 'Barcode' ) + p( 'Code' ) + p( 'Description' ) + p( 'GroupId' ) + p( 'GroupName' ) + p( 'LineNo' ) + p( 'Name' ) + p( 'UnitPrice' ) + p( 'UnitProduct' ) +
		key;
	f.Signature = crypto.createHash( 'md5' ).update( raw, 'utf8' ).digest( 'base64' );
	return f;
}

async function submitForm( page, fields ) {
	const inputs = Object.entries( fields ).map( ( [ k, v ] ) => `<input type="hidden" name="${ k.replace( /"/g, '' ) }" value="${ String( v ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ) }">` ).join( '' );
	await page.setContent( `<form id="f" method="POST" action="${ FORM }">${ inputs }</form>` );
	await Promise.all( [ page.waitForNavigation( { timeout: 60000 } ).catch( () => {} ), page.evaluate( () => document.getElementById( 'f' ).submit() ) ] );
	await page.waitForLoadState( 'networkidle', { timeout: 15000 } ).catch( () => {} );
	await page.waitForTimeout( 3000 );
	return page.evaluate( () => ( { url: location.href.slice( 0, 160 ), text: document.body.innerText.replace( /\s+/g, ' ' ).slice( 0, 500 ) } ) );
}

async function fillCard( page ) {
	let numberFrame;
	let cvvFrame;
	for ( let i = 0; i < 30 && ( ! numberFrame || ! cvvFrame ); i++ ) {
		numberFrame = page.frames().find( ( fr ) => fr.url().includes( 'microform' ) && fr.url().includes( '%22fieldType%22:%22number%22' ) );
		cvvFrame = page.frames().find( ( fr ) => fr.url().includes( 'microform' ) && fr.url().includes( 'securityCode' ) );
		if ( ! numberFrame || ! cvvFrame ) {
			await page.waitForTimeout( 500 );
		}
	}
	if ( ! numberFrame ) {
		log( 'fillCard', { frames: page.frames().map( ( fr ) => fr.url().replace( /\?.*/, '' ).slice( 0, 90 ) ) } );
		return false;
	}
	await numberFrame.fill( '#number', CARD.number );
	await cvvFrame.fill( '#securityCode', CARD.cvv );
	await page.click( '#CardExp' );
	await page.keyboard.type( CARD.month + CARD.year, { delay: 80 } );
	await page.waitForTimeout( 800 );
	await page.click( '#submit-default' );
	return true;
}

test( 'В-3: new API finds v0.5 orders', async ( { page } ) => {
	test.setTimeout( 12 * 60000 );
	let returned = null;
	await page.route( /shop\.example/, ( r ) => {
		returned = r.request().url();
		return r.fulfill( { status: 200, contentType: 'text/html', body: '<h1>returned</h1>' } );
	} );
	await auth();

	// ExternalID as the old plugin builds it: order ID + last 10 digits of microtime.
	const postfix = String( Date.now() * 10 ).slice( -10 );
	let external = null;
	for ( const [ name, key ] of Object.entries( KEYS ) ) {
		const ext = '9' + String( Date.now() % 100000 ) + postfix;
		const res = await submitForm( page, legacyForm( ext, key ) );
		const created = await getNew( ext );
		log( 'form key=' + name, { external: ext, page: res, newApiBeforePay: { status: created.status, body: created.text.slice( 0, 400 ) } } );
		const hasCard = page.frames().some( ( fr ) => fr.url().includes( 'microform' ) );
		if ( hasCard || /card|pay/i.test( res.text ) && ! /declined|failure/i.test( res.text ) ) {
			external = ext;
			break;
		}
	}
	if ( ! external ) {
		log( 'RESULT', 'v0.5 form was not accepted with either key — В-3 cannot be checked with the public test credentials' );
		return;
	}

	// Unpaid order: new API and old API.
	log( 'unpaid new API', ( await getNew( external ) ).text );
	log( 'unpaid old API', await getOld( external ) );

	if ( await fillCard( page ) ) {
		await page.waitForURL( /shop\.example/, { timeout: 90000 } ).catch( () => log( 'return', 'no return to shop: ' + page.url().slice( 0, 160 ) ) );
	}
	log( 'return url', returned );
	let g;
	for ( let i = 0; i < 30; i++ ) {
		g = await getNew( external );
		if ( g.json && ( g.json.status === 4 || g.json.status === 5 ) ) {
			break;
		}
		await new Promise( ( r ) => setTimeout( r, 3000 ) );
	}
	log( 'paid new API', { http: g.status, body: g.text } );
	log( 'paid old API', await getOld( external ) );
	log( 'RESULT', { external, newApiStatus: g.json ? g.json.status : null, found: g.status === 200 } );
} );

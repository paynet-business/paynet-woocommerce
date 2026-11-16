// Round 2: what the Paynet page shows for a saved card, unlink vs the page, card expiry in GET.
'use strict';

const { test } = require( '@playwright/test' );

const BASE = 'https://ecom-api-test.paynet.md';
const SA = 'onlidocumentation_md';
const runBase = Date.now() * 1000;
let seq = 0;
let token = null;
const log = ( label, data ) => console.log( '--- DIAG ' + label + ' ' + ( typeof data === 'string' ? data : JSON.stringify( data ) ) );

function chisinau( m ) {
	const p = new Intl.DateTimeFormat( 'en-GB', { timeZone: 'Europe/Chisinau', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false } )
		.formatToParts( new Date( Date.now() + m * 60000 ) ).reduce( ( a, x ) => ( ( a[ x.type ] = x.value ), a ), {} );
	return `${ p.year }-${ p.month }-${ p.day }T${ p.hour }:${ p.minute }:${ p.second }`;
}
async function call( method, path, body ) {
	if ( ! token ) {
		const r = await fetch( BASE + '/auth', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { grantType: 'password', username: '771064', password: 'sLgMnGWJ' } ) } );
		token = ( await r.json() ).access_token;
	}
	const r = await fetch( BASE + path, { method, headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token }, body: body ? JSON.stringify( body ) : undefined } );
	const t = await r.text();
	let j = null;
	try {
		j = JSON.parse( t );
	} catch ( e ) {}
	return { status: r.status, json: j };
}
async function create( code, hash ) {
	seq += 1;
	const order = runBase + seq;
	const body = { Order: order, SaleAreaCode: SA, LinkUrlSuccess: 'https://shop.example/success', LinkUrlCancel: 'https://shop.example/cancel', Customer: { Code: code, NameFirst: 'Ion', NameLast: 'Popescu', email: 'ion@example.com' }, Currency: 498, ExpiryDate: chisinau( 60 ), Services: [ { Name: 'Probe', Description: 'R2 ' + seq, Amount: 300, Products: [ { LineNo: 1, Code: 'P', Name: 'P', Description: 'P', UnitPrice: 300, Quantity: 100, Amount: 300, TotalAmount: 300 } ] } ], MoneyType: 'All', Lang: 'EN' };
	if ( hash ) {
		body.CardLinkHash = hash;
	}
	const r = await call( 'POST', '/api/order', body );
	return { order, link: r.json?.paymentLink };
}
async function cardHtml( page, label ) {
	await page.waitForLoadState( 'networkidle' ).catch( () => {} );
	await page.waitForTimeout( 3500 );
	const html = await page.evaluate( () => {
		const n = document.querySelector( '#new-card' );
		let box = n;
		for ( let i = 0; i < 4 && box && box.parentElement; i++ ) {
			box = box.parentElement;
		}
		const src = ( box || document.body ).outerHTML.replace( /<script[\s\S]*?<\/script>/g, '' ).replace( /<svg[\s\S]*?<\/svg>/g, '<svg/>' ).replace( /\s+/g, ' ' );
		return { visibleNewCard: !! n && ! n.classList.contains( 'display-none' ), html: src.slice( 0, 3500 ) };
	} );
	log( 'html ' + label, html );
	return html;
}
async function pay( page, card, exp ) {
	let nf;
	let cf;
	for ( let i = 0; i < 30 && ( ! nf || ! cf ); i++ ) {
		nf = page.frames().find( ( f ) => f.url().includes( 'microform' ) && f.url().includes( '%22fieldType%22:%22number%22' ) );
		cf = page.frames().find( ( f ) => f.url().includes( 'microform' ) && f.url().includes( 'securityCode' ) );
		if ( ! nf || ! cf ) {
			await page.waitForTimeout( 500 );
		}
	}
	await nf.fill( '#number', card );
	await cf.fill( '#securityCode', '777' );
	await page.click( '#CardExp' );
	await page.keyboard.type( exp, { delay: 120 } );
	await page.waitForTimeout( 800 );
	log( 'CardExp value', await page.inputValue( '#CardExp' ) );
	await page.click( '#submit-default' );
	await page.waitForURL( /shop\.example/, { timeout: 90000 } ).catch( () => {} );
}
async function paid( order, label ) {
	let j;
	for ( let i = 0; i < 30; i++ ) {
		j = ( await call( 'GET', '/api/order/merchant/' + order ) ).json || {};
		if ( j.status === 4 ) {
			break;
		}
		await new Promise( ( r ) => setTimeout( r, 3000 ) );
	}
	log( 'paid ' + label, { status: j.status, hash: j.cardLinkHash, payer: j.payer } );
	return j;
}

test( 'round 2', async ( { page } ) => {
	test.setTimeout( 12 * 60000 );
	page.on( 'dialog', ( d ) => {
		log( 'dialog', d.type() + ': ' + d.message() );
		d.accept().catch( () => {} );
	} );
	await page.route( /shop\.example/, ( r ) => r.fulfill( { status: 200, contentType: 'text/html', body: 'returned' } ) );
	const code = 'probe2-' + runBase;

	// Two cards for one customer.
	const a = await create( code );
	await page.goto( a.link );
	await pay( page, '4111111111111111', '1227' );
	const pa = await paid( a.order, 'visa' );
	const b = await create( code );
	await page.goto( b.link );
	await pay( page, '5555555555554444', '0329' );
	const pb = await paid( b.order, 'mc' );
	const h1 = pa.cardLinkHash;
	const h2 = pb.cardLinkHash;

	// Page with h1: which cards does it list (only h1, or all cards of the customer)?
	const c = await create( code, h1 );
	await page.goto( c.link );
	await cardHtml( page, 'with h1 (two cards linked)' );

	// Unlink h2, then open a page with h2.
	log( 'unlink h2', await call( 'POST', '/api/linked-cards/unlink', { CardLinkHash: h2, SaleAreaCode: SA, CustomerCode: code } ) );
	const d = await create( code, h2 );
	await page.goto( d.link );
	await cardHtml( page, 'with unlinked h2' );

	// Delete h1 on the Paynet page, if the page has a delete control; then unlink h1.
	const e = await create( code, h1 );
	await page.goto( e.link );
	await page.waitForTimeout( 3500 );
	const del = page.locator( '[class*=delete], [class*=remove], [id*=delete], [id*=remove], [title*=elete], [aria-label*=elete], .trash, [class*=trash]' ).first();
	if ( await del.count() ) {
		log( 'delete control', await del.evaluate( ( n ) => n.outerHTML.slice( 0, 400 ) ) );
		await del.click( { force: true } ).catch( ( err ) => log( 'delete click', String( err ).slice( 0, 200 ) ) );
		await page.waitForTimeout( 3000 );
		const confirm = page.locator( 'button:has-text("Yes"), button:has-text("Da"), button:has-text("Да"), button:has-text("OK"), button:has-text("Confirm"), button:has-text("Delete")' ).first();
		if ( await confirm.count() ) {
			await confirm.click( { force: true } ).catch( () => {} );
			await page.waitForTimeout( 3000 );
		}
		await cardHtml( page, 'after delete on page' );
		log( 'unlink h1 after page delete', await call( 'POST', '/api/linked-cards/unlink', { CardLinkHash: h1, SaleAreaCode: SA, CustomerCode: code } ) );
	} else {
		log( 'delete control', 'not found' );
	}
} );

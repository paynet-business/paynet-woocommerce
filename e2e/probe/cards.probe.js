// Probes of the Paynet TEST API for saved cards and refunds (ТЗ В-11…В-13, разд. 9 / 9б).
// Pays real test orders on the Paynet page (no WordPress needed) and prints every finding
// to the job log as "--- DIAG" lines (Actions artifacts are not readable from Claude's workspace).
'use strict';

const { test } = require( '@playwright/test' );

const BASE = 'https://ecom-api-test.paynet.md';
const USER = '771064';
const PASS = 'sLgMnGWJ';
const SA = 'onlidocumentation_md';
const CARDS = {
	visa: { number: '4111111111111111', month: '12', year: '27', cvv: '777' },
	mc: { number: '5555555555554444', month: '12', year: '28', cvv: '123' },
};
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
	return { status: r.status, json, text: text.slice( 0, 600 ) };
}

function customer( code, variant ) {
	return variant === 'changed'
		? { Code: code, NameFirst: 'Changed', NameLast: 'Person', email: 'changed@example.com', PhoneNumber: '37369999999' }
		: { Code: code, NameFirst: 'Ion', NameLast: 'Popescu', email: 'ion@example.com', PhoneNumber: '37360000000' };
}

async function createOrder( cust, hash, amount = 300 ) {
	seq += 1;
	const order = runBase + seq;
	const body = {
		Order: order,
		SaleAreaCode: SA,
		LinkUrlSuccess: 'https://shop.example/success',
		LinkUrlCancel: 'https://shop.example/cancel',
		Customer: cust,
		Currency: 498,
		ExpiryDate: chisinau( 60 ),
		Services: [ { Name: 'Probe shop', Description: 'Card probe ' + seq, Amount: amount, Products: [ { LineNo: 1, Code: 'P', Name: 'Probe', Description: 'Probe', UnitPrice: amount, Quantity: 100, Amount: amount, TotalAmount: amount } ] } ],
		MoneyType: 'All',
		Lang: 'EN',
	};
	if ( hash ) {
		body.CardLinkHash = hash;
	}
	const r = await call( 'POST', '/api/order', body );
	log( 'create ' + order, { status: r.status, paymentId: r.json?.paymentId, code: r.json?.code, hash: hash || null, customer: cust.Code + '/' + cust.NameFirst } );
	return { order, link: r.json?.paymentLink, paymentId: r.json?.paymentId };
}

async function dump( page, label ) {
	await page.waitForLoadState( 'networkidle' ).catch( () => {} );
	await page.waitForTimeout( 3500 );
	const info = await page.evaluate( () => ( {
		url: location.href.slice( 0, 120 ),
		text: document.body.innerText.replace( /\s+/g, ' ' ).slice( 0, 1200 ),
		els: [ ...document.querySelectorAll( 'input,button,select,a,label,[role=button],[onclick]' ) ]
			.filter( ( e ) => e.offsetParent !== null || e.type === 'checkbox' || e.type === 'radio' )
			.map( ( e ) => `${ e.tagName }#${ e.id } .${ String( e.className || '' ).slice( 0, 50 ) } type=${ e.type || '' } name=${ e.name || '' } checked=${ e.checked } for=${ e.htmlFor || '' } txt=${ ( e.innerText || e.value || e.title || '' ).replace( /\s+/g, ' ' ).slice( 0, 50 ) }` )
			.slice( 0, 70 ),
	} ) );
	info.frames = page.frames().map( ( f ) => f.url().replace( /\?.*/, '' ).slice( 0, 90 ) + ( f.url().includes( 'fieldType%22:%22number' ) ? ' [number]' : '' ) + ( f.url().includes( 'securityCode' ) ? ' [cvv]' : '' ) );
	log( 'page ' + label, info );
	return info;
}

async function openPage( page, link ) {
	await page.goto( link );
	await page.waitForURL( /paynet\.md/, { timeout: 60000 } );
}

async function fillCard( page, card ) {
	let numberFrame;
	let cvvFrame;
	for ( let i = 0; i < 30 && ( ! numberFrame || ! cvvFrame ); i++ ) {
		numberFrame = page.frames().find( ( f ) => f.url().includes( 'microform' ) && f.url().includes( '%22fieldType%22:%22number%22' ) );
		cvvFrame = page.frames().find( ( f ) => f.url().includes( 'microform' ) && f.url().includes( 'securityCode' ) );
		if ( ! numberFrame || ! cvvFrame ) {
			await page.waitForTimeout( 500 );
		}
	}
	if ( ! numberFrame ) {
		log( 'fillCard', 'no card number frame' );
		return false;
	}
	await numberFrame.fill( '#number', card.number );
	await cvvFrame.fill( '#securityCode', card.cvv );
	await page.click( '#CardExp' );
	await page.keyboard.type( card.month + card.year, { delay: 80 } );
	await page.waitForTimeout( 800 );
	await page.click( '#submit-default' );
	return true;
}

async function waitPaid( order, label ) {
	let g;
	for ( let i = 0; i < 40; i++ ) {
		g = await call( 'GET', '/api/order/merchant/' + order );
		if ( g.json?.status === 4 || g.json?.status === 5 ) {
			break;
		}
		await new Promise( ( r ) => setTimeout( r, 3000 ) );
	}
	const j = g.json || {};
	log( 'paid ' + label, { status: j.status, cardLinkHash: j.cardLinkHash, customerHash: j.customer?.cardLinkHash, payer: j.payer, moneyType: j.moneyType, paidAmount: j.amount?.paidAmount, paidDate: j.paidDate, customer: j.customer } );
	return j;
}

async function payNew( page, cust, card, label, hash ) {
	const o = await createOrder( cust, hash );
	await openPage( page, o.link );
	await dump( page, label + ' before pay' );
	if ( ! ( await fillCard( page, card ) ) ) {
		return { ...o, paid: {} };
	}
	await page.waitForURL( /shop\.example/, { timeout: 90000 } ).catch( () => log( 'return ' + label, 'no return to shop: ' + page.url().slice( 0, 120 ) ) );
	return { ...o, paid: await waitPaid( o.order, label ) };
}

test( 'saved cards and refunds on the test API', async ( { page } ) => {
	test.setTimeout( 15 * 60000 );
	await page.route( /shop\.example/, ( r ) => r.fulfill( { status: 200, contentType: 'text/html', body: '<h1>returned</h1>' } ) );
	await auth();
	const code = 'probe-' + runBase;

	// 1. First payment, save checkbox left as is (default on per docs).
	const a = await payNew( page, customer( code ), CARDS.visa, 'A visa first' );
	const h1 = a.paid.cardLinkHash || a.paid.customer?.cardLinkHash;

	// 2. В-12: same card again for the same customer, without CardLinkHash.
	const b = await payNew( page, customer( code ), CARDS.visa, 'B visa again (no hash)' );
	const h2 = b.paid.cardLinkHash || b.paid.customer?.cardLinkHash;
	log( 'В-12', { h1, h2, same: !! h1 && h1 === h2 } );

	// 3. Second card (Mastercard test number) — scenario 28.
	const f = await payNew( page, customer( code ), CARDS.mc, 'F mastercard' );
	const h3 = f.paid.cardLinkHash || f.paid.customer?.cardLinkHash;
	log( 'second card', { status: f.paid.status, h3, differs: !! h3 && h3 !== h1 } );

	if ( h1 ) {
		// 4. Control: same Customer + hash → saved card shown.
		const c = await createOrder( customer( code ), h1 );
		await openPage( page, c.link );
		const pc = await dump( page, 'C same customer + h1' );
		// 5. В-11: same Code, other name/email/phone + hash.
		const d = await createOrder( customer( code, 'changed' ), h1 );
		await openPage( page, d.link );
		const pd = await dump( page, 'D changed name/email/phone + h1' );
		// 6. Negative control: other Code + hash.
		const e = await createOrder( customer( code + '-x' ), h1 );
		await openPage( page, e.link );
		const pe = await dump( page, 'E other code + h1' );
		const shows = ( p ) => /1111/.test( p.text );
		log( 'В-11', { sameCustomerShows: shows( pc ), changedFieldsShows: shows( pd ), otherCodeShows: shows( pe ) } );

		// 7. Pay with the saved card on the control order page (scenario 29) — try the obvious button.
		await openPage( page, c.link );
		await page.waitForTimeout( 3000 );
		const before = await page.evaluate( () => document.body.innerText.slice( 0, 200 ) );
		const btn = page.locator( '#submit-default, button[type=submit]' ).first();
		if ( await btn.isVisible().catch( () => false ) ) {
			await btn.click().catch( ( err ) => log( 'C click', String( err ).slice( 0, 200 ) ) );
			await page.waitForURL( /shop\.example/, { timeout: 60000 } ).catch( () => {} );
			await dump( page, 'C after submit with saved card' );
			await waitPaid( c.order, 'C saved card' );
		} else {
			log( 'C', 'no submit button visible; start of page: ' + before );
		}
	}

	// 8. В-13: unlink variants (use h2/h3 if different from h1 to keep h1 for later checks).
	const target = h3 || h2 || h1;
	if ( target ) {
		const u = ( body ) => call( 'POST', '/api/linked-cards/unlink', body );
		log( 'unlink wrong customer', await u( { CardLinkHash: target, SaleAreaCode: SA, CustomerCode: code + '-x' } ) );
		log( 'unlink extra field', await u( { CardLinkHash: target, SaleAreaCode: SA, CustomerCode: code, Mode: 'test' } ) );
		log( 'unlink ok', await u( { CardLinkHash: target, SaleAreaCode: SA, CustomerCode: code } ) );
		log( 'unlink again', await u( { CardLinkHash: target, SaleAreaCode: SA, CustomerCode: code } ) );
		log( 'unlink unknown hash', await u( { CardLinkHash: '1_00000000000000000000000000000000', SaleAreaCode: SA, CustomerCode: code } ) );
		const g = await createOrder( customer( code ), target );
		await openPage( page, g.link );
		const pg = await dump( page, 'G after unlink + same hash' );
		log( 'after unlink', { cardShown: /1111|4444/.test( pg.text ) } );
	}

	// 9. Refunds (разд. 9): full with Amount = paid; partial twice; above paid; full with smaller amount.
	const refund = ( pid, amount, type ) => call( 'POST', '/api/order/refund', { PaymentId: pid, Amount: amount, SaleAreaCode: SA, Currency: 498, RefundType: type, Reason: 'probe' } );
	if ( a.paid.status === 4 ) {
		log( 'refund full A', await refund( a.paymentId, a.paid.amount?.paidAmount || 300, 'full' ) );
		log( 'A after full', ( await call( 'GET', '/api/order/merchant/' + a.order ) ).json?.status );
	}
	if ( b.paid.status === 4 ) {
		log( 'refund partial B #1', await refund( b.paymentId, 100, 'partial' ) );
		log( 'B after #1', ( await call( 'GET', '/api/order/merchant/' + b.order ) ).json?.status );
		log( 'refund partial B #2', await refund( b.paymentId, 100, 'partial' ) );
		log( 'refund B above rest', await refund( b.paymentId, 250, 'partial' ) );
		log( 'B after', ( await call( 'GET', '/api/order/merchant/' + b.order ) ).json?.status );
	}
	if ( f.paid.status === 4 ) {
		log( 'refund F full with smaller Amount', await refund( f.paymentId, 100, 'full' ) );
		log( 'F after', ( await call( 'GET', '/api/order/merchant/' + f.order ) ).json );
	}
} );

#!/usr/bin/env node
// Probes of the Paynet TEST Ecom API (public shared credentials from docs.paynet.md).
// Answers questions the documentation does not cover: max field lengths (ТЗ В-10) and
// request rules that affect the plugin. Writes Markdown to $GITHUB_STEP_SUMMARY and JSON to argv[2].
'use strict';

const fs = require( 'fs' );

const BASE = 'https://ecom-api-test.paynet.md';
const USER = '771064';
const PASS = 'sLgMnGWJ';
const SALE_AREA = 'onlidocumentation_md';
const out = process.argv[ 2 ] || 'probe-results.json';
const summaryFile = process.env.GITHUB_STEP_SUMMARY;

let token = null;
let seq = 0;
const runBase = Date.now() * 1000; // ~1.8e15 < 2^53, unique per run.
const results = { lengths: {}, rules: [], startedAt: new Date().toISOString() };

function chisinau( minutesAhead ) {
	const d = new Date( Date.now() + minutesAhead * 60000 );
	const parts = new Intl.DateTimeFormat( 'en-GB', {
		timeZone: 'Europe/Chisinau', year: 'numeric', month: '2-digit', day: '2-digit',
		hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
	} ).formatToParts( d ).reduce( ( a, p ) => ( ( a[ p.type ] = p.value ), a ), {} );
	return `${ parts.year }-${ parts.month }-${ parts.day }T${ parts.hour }:${ parts.minute }:${ parts.second }`;
}

function nextOrder() {
	seq += 1;
	return runBase + seq;
}

async function auth() {
	const r = await fetch( BASE + '/auth', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { grantType: 'password', username: USER, password: PASS } ),
	} );
	const j = await r.json();
	token = j.access_token;
	return { status: r.status, expires_in: j.expires_in };
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
	return { status: r.status, json, text: text.slice( 0, 400 ) };
}

function baseOrder( order ) {
	return {
		Order: order,
		SaleAreaCode: SALE_AREA,
		LinkUrlSuccess: 'https://shop.example/success',
		LinkUrlCancel: 'https://shop.example/cancel',
		Customer: { Code: 'probe-1', NameFirst: 'Probe', NameLast: 'Check', email: 'probe@example.com', PhoneNumber: '37360000000' },
		Currency: 498,
		ExpiryDate: chisinau( 60 ),
		Services: [ {
			Name: 'Probe shop',
			Description: 'Order probe',
			Amount: 300,
			Products: [ { LineNo: 1, Code: 'SKU-1', Name: 'Probe product', Description: 'Probe', UnitPrice: 300, Quantity: 100, Amount: 300, TotalAmount: 300 } ],
		} ],
		MoneyType: 'All',
		Lang: 'EN',
	};
}

// Field setters and getters (value read back from GET /api/order/merchant/{Order}).
const FIELDS = {
	// GET returns services[] in a different shape than the request: no reliable read-back, accept/reject only.
	'Services.Name': { set: ( b, v ) => ( b.Services[ 0 ].Name = v ), get: () => undefined },
	'Services.Description': { set: ( b, v ) => ( b.Services[ 0 ].Description = v ), get: () => undefined },
	'Products.Name': { set: ( b, v ) => ( b.Services[ 0 ].Products[ 0 ].Name = v ), get: () => undefined },
	'Products.Description': { set: ( b, v ) => ( b.Services[ 0 ].Products[ 0 ].Description = v ), get: () => undefined },
	'Products.Code': { set: ( b, v ) => ( b.Services[ 0 ].Products[ 0 ].Code = v ), get: () => undefined },
	'Customer.Code': { set: ( b, v ) => ( b.Customer.Code = v ), get: ( p ) => p.customer?.code },
	'Customer.NameFirst': { set: ( b, v ) => ( b.Customer.NameFirst = v ), get: ( p ) => p.customer?.nameFirst },
	'Customer.NameLast': { set: ( b, v ) => ( b.Customer.NameLast = v ), get: ( p ) => p.customer?.nameLast },
	'Customer.email': { set: ( b, v ) => ( b.Customer.email = 'a'.repeat( Math.max( 1, v.length - 12 ) ) + '@example.com' ), get: ( p ) => p.customer?.email, asciiOnly: true },
	'Customer.PhoneNumber': { set: ( b, v ) => ( b.Customer.PhoneNumber = '3'.repeat( v.length ) ), get: ( p ) => p.customer?.phoneNumber, asciiOnly: true },
	'LinkUrlSuccess': { set: ( b, v ) => ( b.LinkUrlSuccess = 'https://shop.example/?q=' + 'a'.repeat( Math.max( 1, v.length - 24 ) ) ), get: ( p ) => p.linkUrlSuccess, asciiOnly: true },
};

function makeValue( len, cyr ) {
	return ( cyr ? 'Ж' : 'a' ).repeat( len );
}

async function tryLen( field, len, cyr ) {
	const order = nextOrder();
	const body = baseOrder( order );
	const value = makeValue( len, cyr );
	FIELDS[ field ].set( body, value );
	const sent = JSON.stringify( body ).length;
	const r = await call( 'POST', '/api/order', body );
	let stored = null;
	if ( 200 === r.status ) {
		const g = await call( 'GET', '/api/order/merchant/' + order );
		const v = g.json ? FIELDS[ field ].get( g.json ) : undefined;
		stored = 'string' === typeof v ? v.length : null;
	}
	const code = r.json?.code || r.json?.errorInfo?.ppResponseCodeText || '';
	const detail = r.json?.errorInfo?.errors ? JSON.stringify( r.json.errorInfo.errors ).slice( 0, 200 ) : ( r.json?.details || r.json?.message || '' );
	return { len, cyr, status: r.status, ok: 200 === r.status, stored, code, detail, sent };
}

async function probeField( field ) {
	const log = [];
	const levels = [ 50, 100, 255, 500, 1000, 2000 ];
	let lastOk = 0;
	let firstFail = null;
	for ( const len of levels ) {
		const t = await tryLen( field, len, false );
		log.push( t );
		if ( t.ok && ( null === t.stored || t.stored === len ) ) {
			lastOk = len;
		} else {
			firstFail = len;
			break;
		}
	}
	// Binary search for the exact boundary.
	if ( null !== firstFail ) {
		let lo = lastOk;
		let hi = firstFail;
		while ( hi - lo > 1 ) {
			const mid = Math.floor( ( lo + hi ) / 2 );
			const t = await tryLen( field, mid, false );
			log.push( t );
			if ( t.ok && ( null === t.stored || t.stored === mid ) ) {
				lo = mid;
			} else {
				hi = mid;
			}
		}
		lastOk = lo;
		firstFail = hi;
	}
	const res = { maxAscii: lastOk, firstFailAscii: firstFail, failMode: null, cyrAtMax: null, log };
	const failing = log.find( ( t ) => t.len === firstFail );
	if ( failing ) {
		res.failMode = failing.ok ? `accepted, stored ${ failing.stored } chars (silently cut)` : `HTTP ${ failing.status } ${ failing.code }`;
	}
	// Cyrillic at the ASCII maximum: chars or bytes?
	if ( ! FIELDS[ field ].asciiOnly && lastOk > 0 ) {
		const c = await tryLen( field, lastOk, true );
		log.push( c );
		res.cyrAtMax = c.ok && ( null === c.stored || c.stored === lastOk ) ? 'ok (limit counts characters)' : `fails (${ c.ok ? 'stored ' + c.stored : 'HTTP ' + c.status + ' ' + c.code }) — limit may count bytes`;
		if ( ! ( c.ok && ( null === c.stored || c.stored === lastOk ) ) ) {
			const half = await tryLen( field, Math.floor( lastOk / 2 ), true );
			log.push( half );
			res.cyrHalf = half.ok ? 'ok at half length' : 'fails at half length';
		}
	}
	return res;
}

async function rule( name, mutate, opts = {} ) {
	const order = nextOrder();
	const body = baseOrder( order );
	mutate( body );
	const r = await call( 'POST', '/api/order', body );
	let extra = '';
	if ( 200 === r.status && opts.readBack ) {
		const g = await call( 'GET', '/api/order/merchant/' + order );
		extra = opts.readBack( g.json || {} );
	}
	const entry = { name, status: r.status, code: r.json?.code || r.json?.errorInfo?.ppResponseCodeText || '', detail: ( r.json?.errorInfo?.errors ? JSON.stringify( r.json.errorInfo.errors ) : r.json?.details || '' ).slice( 0, 200 ), extra };
	results.rules.push( entry );
	return { r, order, body };
}

async function main() {
	const a = await auth();
	results.auth = a;
	const only = ( process.env.PROBE_ONLY || '' ).split( ',' ).filter( Boolean );
	const want = ( group ) => ! only.length || only.includes( group );

	if ( want( 'round2' ) ) {
		const shape = await rule( 'GET services shape (Name=SVC-NAME, Description=SVC-DESC, product P-NAME/P-DESC)', ( b ) => {
			b.Services[ 0 ].Name = 'SVC-NAME';
			b.Services[ 0 ].Description = 'SVC-DESC';
			b.Services[ 0 ].Products[ 0 ].Name = 'P-NAME';
			b.Services[ 0 ].Products[ 0 ].Description = 'P-DESC';
		}, { readBack: ( p ) => JSON.stringify( p.services ) } );
		for ( const m of [ 3, 4, 5, 6, 8, 10, 15 ] ) {
			await rule( `ExpiryDate in ${ m } min`, ( b ) => ( b.ExpiryDate = chisinau( m ) ) );
		}
		await rule( 'Emoji in product name', ( b ) => ( b.Services[ 0 ].Products[ 0 ].Name = 'Cake 🎂 Țară' ), { readBack: ( p ) => JSON.stringify( p.services?.[ 0 ] ) } );
		const base = await rule( 'Create (repeat tests, round 2)', () => {} );
		const later = JSON.parse( JSON.stringify( base.body ) );
		later.ExpiryDate = chisinau( 75 );
		const r1 = await call( 'POST', '/api/order', later );
		results.rules.push( { name: 'Same Order, ExpiryDate +15 min', status: r1.status, code: r1.json?.code || '', detail: ( r1.json?.details || '' ).slice( 0, 100 ), extra: 'same paymentId: ' + ( r1.json?.paymentId === base.r.json?.paymentId ) + ', same link: ' + ( r1.json?.paymentLink === base.r.json?.paymentLink ) + ', expiry now: ' + r1.json?.expiryDate } );
		const amt = JSON.parse( JSON.stringify( base.body ) );
		amt.Services[ 0 ].Amount = 400;
		Object.assign( amt.Services[ 0 ].Products[ 0 ], { UnitPrice: 400, Amount: 400, TotalAmount: 400 } );
		const r2 = await call( 'POST', '/api/order', amt );
		results.rules.push( { name: 'Same Order, Amount 300 → 400', status: r2.status, code: r2.json?.code || '', detail: ( r2.json?.details || '' ).slice( 0, 100 ), extra: 'same paymentId: ' + ( r2.json?.paymentId === base.r.json?.paymentId ) + ', amount now: ' + JSON.stringify( r2.json?.amount ) } );
		const nm = JSON.parse( JSON.stringify( base.body ) );
		nm.Services[ 0 ].Products[ 0 ].Name = 'Other name';
		const r3 = await call( 'POST', '/api/order', nm );
		results.rules.push( { name: 'Same Order, product name changed', status: r3.status, code: r3.json?.code || '', detail: ( r3.json?.details || '' ).slice( 0, 100 ), extra: 'same paymentId: ' + ( r3.json?.paymentId === base.r.json?.paymentId ) } );
	}
	if ( ! want( 'rules' ) && ! want( 'lengths' ) ) {
		return finish();
	}

	// Rules that affect the plugin.
	if ( want( 'rules' ) ) {
	await rule( 'Products sum (200) ≠ Services.Amount (300)', ( b ) => {
		b.Services[ 0 ].Products[ 0 ].Amount = 200;
		b.Services[ 0 ].Products[ 0 ].TotalAmount = 200;
		b.Services[ 0 ].Products[ 0 ].UnitPrice = 200;
	} );
	await rule( 'Negative product line (discount -100) with sum = Amount', ( b ) => {
		b.Services[ 0 ].Products[ 0 ].UnitPrice = 400;
		b.Services[ 0 ].Products[ 0 ].Amount = 400;
		b.Services[ 0 ].Products[ 0 ].TotalAmount = 400;
		b.Services[ 0 ].Products.push( { LineNo: 2, Name: 'Discount', UnitPrice: -100, Quantity: 100, Amount: -100, TotalAmount: -100 } );
	} );
	await rule( 'Zero-amount product line', ( b ) => {
		b.Services[ 0 ].Products.push( { LineNo: 2, Name: 'Free gift', UnitPrice: 0, Quantity: 100, Amount: 0, TotalAmount: 0 } );
	} );
	await rule( 'Quantity 150 (1.5 pcs), UnitPrice 200, Amount 300', ( b ) => {
		Object.assign( b.Services[ 0 ].Products[ 0 ], { UnitPrice: 200, Quantity: 150, Amount: 300, TotalAmount: 300 } );
	} );
	await rule( 'UnitPrice × Quantity ≠ Amount (100 × 2 pcs, Amount 300)', ( b ) => {
		Object.assign( b.Services[ 0 ].Products[ 0 ], { UnitPrice: 100, Quantity: 200, Amount: 300, TotalAmount: 300 } );
	} );
	await rule( 'No Products array', ( b ) => {
		delete b.Services[ 0 ].Products;
	} );
	await rule( 'No Customer', ( b ) => {
		delete b.Customer;
	} );
	await rule( 'Customer without PhoneNumber and email', ( b ) => {
		delete b.Customer.PhoneNumber;
		delete b.Customer.email;
	} );
	await rule( 'PhoneNumber "+373 60 000 000"', ( b ) => {
		b.Customer.PhoneNumber = '+373 60 000 000';
	}, { readBack: ( p ) => 'stored: ' + p.customer?.phoneNumber } );
	await rule( 'Invalid email "not-an-email"', ( b ) => {
		b.Customer.email = 'not-an-email';
	} );
	await rule( 'Empty Services.Description', ( b ) => {
		b.Services[ 0 ].Description = '';
	} );
	await rule( 'Lang "ro"', ( b ) => {
		b.Lang = 'ro';
	} );
	await rule( 'ExpiryDate in the past', ( b ) => {
		b.ExpiryDate = chisinau( -60 );
	} );
	await rule( 'ExpiryDate in 2 min', ( b ) => {
		b.ExpiryDate = chisinau( 2 );
	} );
	await rule( 'Currency 840 (USD) on test', ( b ) => {
		b.Currency = 840;
	} );
	await rule( 'Text with \' & " < > and line break', ( b ) => {
		b.Services[ 0 ].Description = 'Mary\'s & "Co" <b>x</b>\nline2';
		b.Services[ 0 ].Products[ 0 ].Name = 'Мария Țurcan & Co';
	}, { readBack: ( p ) => 'stored: ' + JSON.stringify( p.services?.[ 0 ]?.description ) } );

	// Repeat semantics.
	const first = await rule( 'Create (for repeat tests)', () => {} );
	const again = await call( 'POST', '/api/order', first.body );
	results.rules.push( { name: 'Same Order, same body', status: again.status, code: again.json?.code || '', detail: '', extra: 'same link: ' + ( again.json?.paymentLink === first.r.json?.paymentLink ) + ', same paymentId: ' + ( again.json?.paymentId === first.r.json?.paymentId ) } );
	const changed = JSON.parse( JSON.stringify( first.body ) );
	changed.ExpiryDate = chisinau( 61 );
	const diff = await call( 'POST', '/api/order', changed );
	results.rules.push( { name: 'Same Order, ExpiryDate +1 min', status: diff.status, code: diff.json?.code || '', detail: ( diff.json?.details || '' ).slice( 0, 120 ), extra: '' } );
	const g = await call( 'GET', '/api/order/merchant/' + first.order );
	results.rules.push( { name: 'GET /api/order/merchant/{Order} (unpaid)', status: g.status, code: '', detail: '', extra: 'status=' + g.json?.status + ', paymentLink=' + g.json?.paymentLink } );
	const g404 = await call( 'GET', '/api/order/merchant/' + ( runBase - 999 ) );
	results.rules.push( { name: 'GET /api/order/merchant/{unknown Order}', status: g404.status, code: g404.json?.code || '', detail: g404.text.slice( 0, 160 ), extra: '' } );
	const bad = await fetch( BASE + '/api/order/merchant/' + first.order, { headers: { Authorization: 'Bearer invalid' } } );
	results.rules.push( { name: 'GET with invalid token', status: bad.status, code: '', detail: ( await bad.text() ).slice( 0, 160 ), extra: '' } );
	const authBad = await fetch( BASE + '/auth', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { grantType: 'password', username: USER, password: 'wrong' } ) } );
	results.rules.push( { name: 'POST /auth wrong password', status: authBad.status, code: '', detail: ( await authBad.text() ).slice( 0, 200 ), extra: '' } );
	}

	// Field lengths.
	const fieldList = process.env.PROBE_FIELDS ? process.env.PROBE_FIELDS.split( ',' ) : Object.keys( FIELDS );
	for ( const f of want( 'lengths' ) ? fieldList : [] ) {
		results.lengths[ f ] = await probeField( f );
		console.log( f, results.lengths[ f ].maxAscii, results.lengths[ f ].failMode, results.lengths[ f ].cyrAtMax || '' );
	}

	return finish();
}

function finish() {
	fs.writeFileSync( out, JSON.stringify( results, null, 2 ) );

	let md = '## API probes (test environment)\n\n### Field lengths\n\n| Field | Max accepted (ASCII) | Above the max | Cyrillic at max |\n|---|---|---|---|\n';
	for ( const [ f, r ] of Object.entries( results.lengths ) ) {
		md += `| ${ f } | ${ r.firstFailAscii === null ? '≥ ' + r.maxAscii : r.maxAscii } | ${ r.failMode || '—' } | ${ r.cyrAtMax || '—' }${ r.cyrHalf ? ', ' + r.cyrHalf : '' } |\n`;
	}
	md += '\n### Request rules\n\n| Probe | HTTP | Code | Detail | Extra |\n|---|---|---|---|---|\n';
	for ( const r of results.rules ) {
		md += `| ${ r.name } | ${ r.status } | ${ r.code } | ${ String( r.detail ).replace( /\|/g, '/' ).replace( /\n/g, ' ' ) } | ${ String( r.extra ).replace( /\|/g, '/' ) } |\n`;
	}
	console.log( md );
	if ( summaryFile ) {
		fs.appendFileSync( summaryFile, md );
	}
}

main().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );

#!/usr/bin/env node
// Builds the acceptance report (ТЗ 13.1): a Markdown table for the GitHub Actions Summary
// and an ordered list of videos with titles for tools/stage-video.sh.
// Usage: node tools/e2e-report.js <results.json> <summary.md> <videos.tsv> [heading] [video filter]
// Video filter: a spec file name (e.g. stage5.spec.js) or "tz" = only the scenarios of the specification (numeric ids).
// Env TZ_COVERAGE=N: also prints the coverage table of scenarios 1..N and fails if one of them has no test or a failed test.
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );

const [ resultsFile, summaryFile, videosFile, heading = 'E2E scenarios', videoSpec = '' ] = process.argv.slice( 2 );
const report = JSON.parse( fs.readFileSync( resultsFile, 'utf8' ) );
const resultsDir = path.dirname( path.resolve( resultsFile ) );

const rows = [];
const videos = [];
const byId = {};

function walk( suite ) {
	( suite.specs || [] ).forEach( ( spec ) => {
		( spec.tests || [] ).forEach( ( t ) => {
			const r = t.results[ t.results.length - 1 ] || {};
			const ok = r.status === 'passed';
			const id = ( spec.title.match( /^(S\d+-\d+|\d+)\s/ ) || [ '', '—' ] )[ 1 ];
			const name = spec.title.replace( /^(S\d+-\d+|\d+)\s+/, '' );
			const err = ok ? '' : String( ( r.error && r.error.message ) || r.status || '' )
				.replace( /\u001b\[[0-9;]*m/g, '' ).split( '\n' )[ 0 ].replace( /\|/g, '/' ).slice( 0, 200 );
			rows.push( `| ${ id } | ${ name } | ${ ok ? '✅' : '❌' } | ${ err } |` );
			( byId[ id ] = byId[ id ] || [] ).push( ok );
			const inVideo = videoSpec === 'tz' ? /^\d+$/.test( id ) : ( ! videoSpec || String( spec.file || '' ).includes( videoSpec ) );
			( r.attachments || [] ).filter( ( a ) => inVideo && a.name === 'video' && a.path ).forEach( ( a ) => {
				const p = path.isAbsolute( a.path ) ? a.path : path.join( resultsDir, a.path );
				videos.push( [ p, `${ id } — ${ name } — ${ ok ? 'OK' : 'FAIL' }` ].join( '\t' ) );
			} );
		} );
	} );
	( suite.suites || [] ).forEach( walk );
}
( report.suites || [] ).forEach( walk );

const passed = rows.filter( ( r ) => r.includes( '✅' ) ).length;

// Coverage of the specification scenarios (ТЗ, section 13): every number 1..N has at least one test and all of them pass.
const tzCount = parseInt( process.env.TZ_COVERAGE || '0', 10 );
let coverageOk = true;
const coverage = [];
if ( tzCount > 0 ) {
	coverage.push( '', '### Specification scenarios 1–' + tzCount, '', '| # | Checks | Result |', '|---|---|---|' );
	for ( let n = 1; n <= tzCount; n++ ) {
		const r = byId[ String( n ) ] || [];
		const state = r.length === 0 ? '⚠️ no test' : ( r.every( Boolean ) ? '✅' : '❌' );
		if ( state !== '✅' ) {
			coverageOk = false;
		}
		coverage.push( `| ${ n } | ${ r.length } | ${ state } |` );
	}
	coverage.push( '' );
}

const md = [
	'## ' + heading,
	'',
	`Passed: **${ passed } / ${ rows.length }**`,
	'',
	'| # | Scenario | Result | Error |',
	'|---|---|---|---|',
	...rows,
	...coverage,
	'',
	'Video: **Artifacts → video-stage-N** (one MP4, title card before each scenario).',
	'',
].join( '\n' );

fs.appendFileSync( summaryFile, md );
fs.writeFileSync( videosFile, videos.join( '\n' ) + '\n' );
process.exit( passed === rows.length && rows.length > 0 && coverageOk ? 0 : 1 );

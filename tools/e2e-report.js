#!/usr/bin/env node
// Builds the acceptance report (ТЗ 13.1): a Markdown table for the GitHub Actions Summary
// and an ordered list of videos with titles for tools/stage-video.sh.
// Usage: node tools/e2e-report.js <results.json> <summary.md> <videos.tsv>
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );

const [ resultsFile, summaryFile, videosFile ] = process.argv.slice( 2 );
const report = JSON.parse( fs.readFileSync( resultsFile, 'utf8' ) );
const resultsDir = path.dirname( path.resolve( resultsFile ) );

const rows = [];
const videos = [];

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
			( r.attachments || [] ).filter( ( a ) => a.name === 'video' && a.path ).forEach( ( a ) => {
				const p = path.isAbsolute( a.path ) ? a.path : path.join( resultsDir, a.path );
				videos.push( [ p, `${ id } — ${ name } — ${ ok ? 'OK' : 'FAIL' }` ].join( '\t' ) );
			} );
		} );
	} );
	( suite.suites || [] ).forEach( walk );
}
( report.suites || [] ).forEach( walk );

const passed = rows.filter( ( r ) => r.includes( '✅' ) ).length;
const md = [
	'## E2E scenarios',
	'',
	`Passed: **${ passed } / ${ rows.length }**`,
	'',
	'| # | Scenario | Result | Error |',
	'|---|---|---|---|',
	...rows,
	'',
	'Video of the stage: **Artifacts → stage-video** (one MP4, title card before each scenario).',
	'',
].join( '\n' );

fs.appendFileSync( summaryFile, md );
fs.writeFileSync( videosFile, videos.join( '\n' ) + '\n' );
process.exit( passed === rows.length && rows.length > 0 ? 0 : 1 );

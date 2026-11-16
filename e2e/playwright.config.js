// @ts-check
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests',
	workers: 1,
	fullyParallel: false,
	retries: 0,
	timeout: 60000,
	globalSetup: require.resolve( './global-setup.js' ),
	reporter: [
		[ 'list' ],
		[ 'json', { outputFile: 'results/results.json' } ],
	],
	outputDir: 'results/artifacts',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		storageState: 'results/admin.json',
		video: { mode: 'on', size: { width: 1280, height: 720 } },
		viewport: { width: 1280, height: 720 },
		screenshot: 'only-on-failure',
		locale: 'en-US',
	},
} );

// Playwright config for API probes on the Paynet test environment (no WordPress).
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: '.',
	testMatch: /\.probe\.js$/,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	use: { viewport: { width: 1280, height: 900 }, locale: 'en-US' },
} );

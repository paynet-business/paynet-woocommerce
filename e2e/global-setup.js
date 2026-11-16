const { chromium } = require( '@playwright/test' );

// Logs in as the wp-env admin once and stores the session for all tests.
module.exports = async () => {
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( baseURL + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.context().storageState( { path: 'results/admin.json' } );
	await browser.close();
};

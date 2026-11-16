// Stage 2 — plugin skeleton. Test titles start with the scenario ID: it is used in the report and video titles.
const { test, expect } = require( '@playwright/test' );

const SETTINGS = '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=paynet_wc';

async function setMode( page, mode ) {
	await page.goto( SETTINGS );
	await page.selectOption( '#woocommerce_paynet_wc_mode', mode );
	await Promise.all( [ page.waitForNavigation(), page.click( 'button[name="save"]' ) ] );
}

test.describe.configure( { mode: 'serial' } );

test( 'S2-1 Plugin is active and listed in WooCommerce payments', async ( { page } ) => {
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( 'tr[data-slug="paynet-for-woocommerce"].active' ) ).toBeVisible();
	await page.goto( SETTINGS );
	await expect( page.locator( '#woocommerce_paynet_wc_enabled' ) ).toBeVisible();
} );

test( 'S2-2 Test mode: permanent notice about shared account and notifications', async ( { page } ) => {
	await setMode( page, 'test' );
	const notice = page.locator( '.paynet-wc-test-notice' );
	await expect( notice ).toContainText( 'TEST MODE' );
	await expect( notice ).toContainText( 'Payment notifications do not reach your site' );
	await expect( notice ).toContainText( 'order number collision' );
} );

test( 'S2-3 Callback URL is shown with the test-mode hint', async ( { page } ) => {
	await page.goto( SETTINGS );
	await expect( page.locator( '#paynet-wc-callback-url' ) ).toHaveValue( /\?wc-api=WC_Gateway_Paynet$/ );
	await expect( page.getByText( 'In test mode you do not need to send this address' ) ).toBeVisible();
} );

test( 'S2-4 Test salt is 6 digits and "Regenerate" changes it', async ( { page } ) => {
	await page.goto( SETTINGS );
	const salt = page.locator( '.paynet-wc-regenerate-salt' ).locator( 'xpath=preceding-sibling::input[@type="text"]' );
	const before = await salt.inputValue();
	expect( before ).toMatch( /^\d{6}$/ );
	await Promise.all( [ page.waitForNavigation(), page.click( '.paynet-wc-regenerate-salt' ) ] );
	const after = await page.locator( '.paynet-wc-regenerate-salt' ).locator( 'xpath=preceding-sibling::input[@type="text"]' ).inputValue();
	expect( after ).toMatch( /^\d{6}$/ );
	expect( after ).not.toBe( before );
} );

test( 'S2-4b Pressing Enter in a settings field does not change the salt', async ( { page } ) => {
	await page.goto( SETTINGS );
	const salt = () => page.locator( '.paynet-wc-regenerate-salt' ).locator( 'xpath=preceding-sibling::input[@type="text"]' ).inputValue();
	const before = await salt();
	await page.fill( '#woocommerce_paynet_wc_title', 'Card, Apple Pay, Google Pay (Paynet)' );
	await Promise.all( [ page.waitForNavigation(), page.press( '#woocommerce_paynet_wc_title', 'Enter' ) ] );
	expect( await salt() ).toBe( before );
} );

test( 'S2-5 Live mode: no test notice, no salt, hint "send this address to Paynet"', async ( { page } ) => {
	await setMode( page, 'live' );
	await expect( page.locator( '.paynet-wc-test-notice' ) ).toHaveCount( 0 );
	await expect( page.locator( '.paynet-wc-regenerate-salt' ) ).toHaveCount( 0 );
	await expect( page.getByText( 'Send this address to Paynet for your sale area' ) ).toBeVisible();
	await setMode( page, 'test' );
} );

test( 'S2-6 Password fields are masked', async ( { page } ) => {
	await page.goto( SETTINGS );
	for ( const id of [ 'test_password', 'test_notify_key', 'live_password', 'live_notify_key' ] ) {
		await expect( page.locator( '#woocommerce_paynet_wc_' + id ) ).toHaveAttribute( 'type', 'password' );
	}
} );

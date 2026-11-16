=== Paynet for WooCommerce ===
Contributors: paynet
Tags: payment gateway, woocommerce, moldova, apple pay, google pay
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card, Apple Pay and Google Pay payments in WooCommerce through the Paynet payment page.

== Description ==

Paynet for WooCommerce redirects customers to the secure Paynet payment page, where they pay by card, Apple Pay, Google Pay or other methods enabled for the merchant.

Development version. The payment flow is not enabled yet.

Documentation: https://docs.paynet.md/api/
Support: support@paynet.md

== External services ==

This plugin connects to the Paynet Ecom API (ecom-api.paynet.md in live mode, ecom-api-test.paynet.md in test mode) to create payments, check payment status and make refunds, and redirects customers to the Paynet payment page. Order data (order number, amount, currency, items) and customer data (name, e-mail, phone) are sent when a payment is created.

Terms of use and privacy policy: links will be added before release.

== Changelog ==

= 0.1.0 =
* Plugin skeleton: settings, test mode notice, HPOS and Blocks compatibility.

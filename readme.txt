=== Paynet for WooCommerce ===
Contributors: paynet
Tags: payment gateway, moldova, apple pay, google pay, credit card
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card, Apple Pay and Google Pay payments in WooCommerce through the secure Paynet payment page (Moldova).

== Description ==

Paynet for WooCommerce connects your store to the Paynet Ecom API. At checkout the customer is redirected to the secure Paynet payment page and pays by card, Apple Pay, Google Pay or another method that Paynet has enabled for your store. Card data is entered only on the Paynet page and never reaches your site.

= Features =

* Classic checkout and the Checkout block.
* High-Performance Order Storage (HPOS) compatible.
* Currencies: MDL, USD, EUR (USD and EUR are enabled by Paynet for your store; the test environment is MDL only).
* Every payment is confirmed through the Paynet API — the notification and the return address alone never change the order status.
* Background status checks: the order is updated even if the customer closes the tab after paying.
* Refunds from the order page (full or partial; Paynet allows one refund per order, within 180 days).
* Saved cards for registered customers (optional): choose or delete a saved card at checkout and in My account. Consent to save a card is given on the Paynet page.
* Test mode with the public Paynet test account, "Fill with test credentials" and "Check connection" buttons.
* The paid amount is checked against the order: a smaller amount (for example, a discount set up by Paynet) is marked for review, a larger or different one puts the order on hold.
* Payment method icons (Visa, Mastercard, Apple Pay, Google Pay) — choose which to show.
* English, Russian and Romanian; the Paynet page opens in the store language.

= Requirements =

* A merchant agreement with Paynet Services SRL and Ecom API credentials: API login, API password, sale area (SaleArea) and notification key — separate for test and live.
* WooCommerce 8.0 or later, PHP 7.4 or later (64-bit).

Documentation: [docs.paynet.md/api](https://docs.paynet.md/api/) · Support: support@paynet.md

== Installation ==

1. Install and activate the plugin (Plugins → Add New → search "Paynet for WooCommerce").
2. Go to WooCommerce → Settings → Payments → Paynet.
3. Test mode: click "Fill with test credentials", then "Save changes". Click "Check connection".
4. Place a test order and pay with a test card from the Paynet documentation.
5. Live mode: enter your live API login, password, SaleArea and notification key, switch "Mode" to "Live" and save.
6. Send the Callback URL shown in the settings to Paynet for your sale area. Without it Paynet does not send payment notifications (orders are still updated by the return of the customer and by background checks).

One sale area is meant for one website: order numbers and the notification address are tied to the sale area.

= Switching from the old "Paynet Moldova Gateway" plugin =

1. Get the new Ecom API credentials from Paynet (the old plugin's credentials are not used).
2. Install this plugin, enter the credentials and click "Check connection".
3. Make a test payment, then switch to live mode.
4. Deactivate the old plugin. Nothing has to be changed in Paynet: the notification address stays the same.

Orders paid with the old plugin keep working: their notifications and return links are confirmed through the new API. Refunds of such orders are made in the Paynet merchant cabinet.

== Frequently Asked Questions ==

= Why are there no payment notifications in test mode? =

Paynet sends notifications to one address per sale area, and the test sale area is shared by all developers. In test mode the order is updated when the customer returns to your site and by background checks (after 2, 5, 15 and 30 minutes, then hourly until the payment window ends).

= A test payment fails to be created. What should I do? =

The test account is shared, so an order number may already be used by someone else. The plugin adds a random salt to test order numbers and retries once. If errors repeat, click "Regenerate" next to the test order-number salt.

= Which payment methods does the customer see? =

All methods Paynet has enabled for your sale area are shown on the Paynet page. In the plugin settings choose the icons shown at checkout so that they match these methods.

= Can I charge a saved card without the customer? =

No. With a saved card the customer still confirms every payment on the Paynet page.

= What happens to the data when I delete the plugin? =

By default nothing is deleted except pending background checks. If "Delete plugin data when the plugin is deleted" is on, the plugin settings and the copies of saved cards in WordPress are deleted (cards stay saved in Paynet). Order notes and payment data of orders are never deleted.

== Screenshots ==

1. Settings: test mode, credentials, Callback URL, icons and options.
2. Classic checkout with the payment method icons.
3. Checkout block: the customer chooses a saved card or a new card.
4. My account → Payment methods: saved cards with deletion.

== External services ==

This plugin connects to the Paynet Ecom API, a service of Paynet Services SRL (Moldova), to process payments. It is required for the plugin to work.

* **When:** when a customer places an order with Paynet (payment creation), when the customer returns from the payment page, during background status checks, when a notification arrives, when the admin makes a refund or clicks "Check connection", and when a customer deletes a saved card.
* **Where:** `https://ecom-api.paynet.md` (live mode) and `https://ecom-api-test.paynet.md` (test mode); the customer is redirected to the Paynet payment page.
* **What is sent:** API login and password (to obtain an access token); order number, amount, currency, payment window, store name and an order summary; customer first and last name, e-mail, phone and customer ID (for saved cards); return addresses of your store; the saved card reference for payments and deletion of saved cards; the refund amount.
* **What is received:** payment status, paid amount, payment method, masked card number, card expiry and the saved card reference.
* Paynet Services SRL privacy policy: https://info.paynet.md/wallet/document/saveas/privacy%20policy%20paynet.pdf
* Terms of service: https://paynet.md

== Changelog ==

= 1.0.0 =
* First release on WordPress.org: payments through the Paynet Ecom API (classic checkout, Checkout block, HPOS), refunds, saved cards, test mode, switching from "Paynet Moldova Gateway", English, Russian and Romanian.

== Upgrade Notice ==

= 1.0.0 =
First release. If you use the old "Paynet Moldova Gateway" plugin, see "Switching from the old plugin" in the installation notes.

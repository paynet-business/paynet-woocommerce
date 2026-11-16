# Paynet for WooCommerce

WooCommerce payment gateway for the Paynet Ecom API (card, Apple Pay, Google Pay via the Paynet payment page).

Status: **stage 2 — skeleton**. Settings and test-mode notice are in place; the payment flow arrives in stage 3, so the gateway is not shown to customers yet.

## Try it without installing anything

[Open in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/paynet-business/paynet-woocommerce/main/playground/blueprint.json) — WordPress + WooCommerce + this plugin in your browser. Requires the repository to be public.

## Acceptance of each stage

GitHub → **Actions** → latest run of **CI**:

1. **Summary** — table of scenarios (✅/❌), unit tests, Plugin Check, Playground link.
2. **Artifacts → stage-video** — one MP4 with all scenarios of the stage.

## Development

```bash
composer install && vendor/bin/phpunit          # unit tests
cd e2e && npm install && npx wp-env start --config ../.wp-env.json && npx playwright test
```

Documentation: https://docs.paynet.md/api/ · License: GPLv2 or later

# Paynet for WooCommerce

WooCommerce payment gateway for the Paynet Ecom API (card, Apple Pay, Google Pay via the Paynet payment page).

Status: **stage 7 — release candidate 1.0.0, final test run on the installation archive** (payments, refunds, saved cards, switching from the old plugin, EN/RU/RO). Description for WordPress.org: `readme.txt`.

## Try it without installing anything

[Open in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/paynet-business/paynet-woocommerce/main/playground/blueprint.json) — WordPress + WooCommerce + this plugin in your browser. Requires the repository to be public.

## Acceptance of each stage

GitHub → **Actions** → latest run of **CI**:

1. **Summary** — table of scenarios (✅/❌), unit tests, Plugin Check, Playground link.
2. **Artifacts → video-stage-N** — one MP4 with the scenarios of the stage (the final run: all specification scenarios, x3 speed).
3. **Artifacts → paynet-for-woocommerce-zip** — the archive for manual installation (`paynet-for-woocommerce-1.0.0.zip` + checksum). The final E2E run installs the plugin from this very archive.

## Development

```bash
composer install && vendor/bin/phpunit          # unit tests
cd e2e && npm install && npx wp-env start --config ../.wp-env.json && npx playwright test
```

### Translations

Source strings are English. Russian and Romanian are bundled in `languages/` (a language pack from translate.wordpress.org takes priority once it exists). No WP-CLI needed:

```bash
php tools/i18n.php pot     # refresh languages/paynet-for-woocommerce.pot from the code
php tools/i18n.php po      # add new strings to the .po files (empty), drop obsolete ones
# translate the empty msgstr in languages/*.po (any text editor or Poedit)
php tools/i18n.php mo      # compile .mo
php tools/i18n.php check   # what CI runs: everything translated, placeholders match, files up to date
```

Documentation: https://docs.paynet.md/api/ · License: GPLv2 or later

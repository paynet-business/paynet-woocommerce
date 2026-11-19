#!/usr/bin/env bash
# Builds the archive for manual installation (and for the WordPress.org upload) and checks it.
# Usage: tools/build-dist.sh [output dir, default: build]
# Result: <output dir>/paynet-for-woocommerce-<version>.zip (inside: one folder paynet-for-woocommerce/)
#         <output dir>/paynet-for-woocommerce-<version>.zip.sha256
# What goes in is decided by .distignore (same file the Plugin Check job uses).
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="paynet-for-woocommerce"
OUT="${1:-build}"

# --- 1. The version must be the same in all three places ----------------------------------------
V_HEADER="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | head -n1 | tr -d '[:space:]')"
V_CONST="$(sed -n "s/.*define( 'PAYNET_WC_VERSION', '\([^']*\)'.*/\1/p" "$SLUG.php" | head -n1)"
V_STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -n1 | tr -d '[:space:]')"
if [ -z "$V_HEADER" ] || [ "$V_HEADER" != "$V_CONST" ] || [ "$V_HEADER" != "$V_STABLE" ]; then
	echo "Version mismatch: plugin header='$V_HEADER', PAYNET_WC_VERSION='$V_CONST', readme.txt Stable tag='$V_STABLE'" >&2
	exit 1
fi
VERSION="$V_HEADER"

# --- 2. Copy by .distignore, then zip -----------------------------------------------------------
STAGE_DIR="$OUT/dist"
ZIP="$OUT/$SLUG-$VERSION.zip"
rm -rf "$STAGE_DIR" "$ZIP" "$ZIP.sha256"
mkdir -p "$STAGE_DIR/$SLUG"
if command -v rsync > /dev/null; then
	rsync -a --exclude-from=.distignore --exclude="/$OUT" ./ "$STAGE_DIR/$SLUG/"
else
	tar -c --exclude-from=.distignore --exclude="./$OUT" . | tar -x -C "$STAGE_DIR/$SLUG"
fi
( cd "$STAGE_DIR" && zip -qr -X "../$(basename "$ZIP")" "$SLUG" )

# --- 3. Check what is inside --------------------------------------------------------------------
CHECK="$(mktemp -d)"
trap 'rm -rf "$CHECK"' EXIT
unzip -q "$ZIP" -d "$CHECK"
ROOT="$CHECK/$SLUG"
[ -d "$ROOT" ] || { echo "The archive must contain the single folder $SLUG/" >&2; exit 1; }
[ "$(ls -A "$CHECK" | wc -l)" = "1" ] || { echo "The archive must contain only the folder $SLUG/" >&2; exit 1; }

for need in "$SLUG.php" readme.txt uninstall.php includes assets/js/blocks.js assets/css/cards.css \
	languages/$SLUG.pot languages/$SLUG-ru_RU.mo languages/$SLUG-ro_RO.mo; do
	[ -e "$ROOT/$need" ] || { echo "Missing in the archive: $need" >&2; exit 1; }
done

BAD="$(cd "$ROOT" && find . \( -name e2e -o -name tests -o -name tools -o -name playground -o -name vendor \
	-o -name node_modules -o -name .github -o -name build -o -name 'composer.*' -o -name 'phpunit*' \
	-o -name README.md -o -name '.git*' -o -name '.wp-env.json' -o -name '*.log' \) | head)"
if [ -n "$BAD" ]; then
	echo "Development files in the archive:" >&2
	echo "$BAD" >&2
	exit 1
fi

# Every PHP file must at least parse; the main file must be the one WordPress reads the header from.
( cd "$ROOT" && find . -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null )

( cd "$OUT" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )
echo "Archive: $ZIP ($(du -h "$ZIP" | cut -f1), $(unzip -Z1 "$ZIP" | grep -vc '/$') files), version $VERSION"
cat "$ZIP.sha256"

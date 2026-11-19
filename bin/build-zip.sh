#!/usr/bin/env bash
#
# Builds an installable zip in dist/ (gitignored), honoring .distignore.
# Usage: bin/build-zip.sh
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="fulfillment-events-for-woocommerce"
VERSION="$(sed -n "s/^ \* Version:[[:space:]]*//p" "$SLUG.php" | head -1)"
OUT="dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG" "$OUT"
rsync -a --exclude-from=.distignore ./ "$STAGE/$SLUG/"
rm -f "$OUT/$SLUG-$VERSION.zip"
(cd "$STAGE" && zip -qr "$OLDPWD/$OUT/$SLUG-$VERSION.zip" "$SLUG")
echo "Built $OUT/$SLUG-$VERSION.zip"

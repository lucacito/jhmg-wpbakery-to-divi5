#!/usr/bin/env bash
# Builds the free plugin's wordpress.org submission zip into dist/.
#
# The version is read off the plugin header rather than passed in, so the zip
# can never be named something the plugin does not call itself. The archive
# holds exactly one top-level folder — the plugin slug — which is what the
# directory (and a manual "upload plugin") expects, and no macOS .DS_Store.
#
# This only packages. The release gate is scripts/plugin-check.sh free; run it
# first (RELEASE.md says so) — a zip is not evidence of anything.
#
# Usage: scripts/build-submission-zip.sh
# Prints: the zip path and its SHA-256.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

SLUG=jhmg-converter-for-wpbakery-to-divi
SRC="plugin/$SLUG"
MAIN="$SRC/$SLUG.php"

[ -d "$SRC" ] || { echo "Not a directory: $SRC" >&2; exit 1; }
[ -f "$MAIN" ] || { echo "Not a file: $MAIN" >&2; exit 1; }

# `sed -n '…{p;q;}'` rather than `sed … | head -n 1`: under `pipefail`, head
# closing the pipe after the first line makes sed exit 141 and takes the whole
# script with it.
VERSION=$(sed -n '/^[[:space:]]*\*[[:space:]]*Version:/{s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p;q;}' "$MAIN")
if [ -z "$VERSION" ]; then
  echo "No 'Version:' in the plugin header of $MAIN" >&2
  exit 1
fi

ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
mkdir -p "$ROOT/dist"
# zip updates an existing archive in place, which would keep files a build no
# longer ships. Always start from nothing.
rm -f "$ZIP"

# -X drops the extra file attributes (uid/gid, resource forks) that make the
# archive machine-specific; the exclusions are macOS's own droppings.
( cd plugin && zip -r -q -X "$ZIP" "$SLUG" -x '*.DS_Store' -x '__MACOSX/*' )

if command -v shasum >/dev/null 2>&1; then
  SHA=$(shasum -a 256 "$ZIP" | awk '{print $1}')
else
  SHA=$(sha256sum "$ZIP" | awk '{print $1}')
fi

echo "$ZIP"
echo "sha256  $SHA"

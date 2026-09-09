#!/usr/bin/env bash
# Extracts the Ronneby theme's demo content into "wpbakery templates/ronneby/".
#
# Ronneby ships one WordPress export per demo site in Mainfiles/import/<demo>/content.xml.
# Those exports are the largest body of real WPBakery content this project has — whole
# themed sites, not element demos — so ThirdPartyTemplateConversionTest runs against them.
# The Elementor demos are skipped: they carry no WPBakery shortcodes.
#
# Only three of the exports are committed (see "wpbakery templates/README.md"); the rest are
# gitignored and re-extracted on demand with this script. Files that already exist are left
# alone, so a re-run never overwrites a reviewed sample.
#
# Usage: scripts/extract-ronneby-corpus.sh [path/to/theme.zip]
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
ZIP=${1:-$ROOT/references/themeforest-s0tt3MTV-ronneby-highperformance-wordpress-theme.zip}
OUT="$ROOT/wpbakery templates/ronneby"

if [ ! -f "$ZIP" ]; then
  echo "no such zip: $ZIP" >&2
  echo "usage: $0 [path/to/theme.zip]" >&2
  exit 1
fi

mkdir -p "$OUT"

written=0
skipped=0
elementor=0

while IFS= read -r entry; do
  demo=$(basename "$(dirname "$entry")")

  case "$demo" in
    *elementor*|*Elementor*) elementor=$((elementor + 1)); continue ;;
  esac

  target="$OUT/$demo.xml"

  if [ -f "$target" ]; then
    skipped=$((skipped + 1))
    continue
  fi

  unzip -p "$ZIP" "$entry" > "$target"
  written=$((written + 1))
done < <(unzip -Z1 "$ZIP" 'Mainfiles/import/*/content.xml' | sort)

echo "wrote $written demo exports to $OUT, skipped $skipped already there, ignored $elementor Elementor demos"

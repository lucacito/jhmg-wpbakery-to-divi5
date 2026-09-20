#!/usr/bin/env bash
# Boots the local WordPress (Divi 5 + WPBakery Page Builder 9.0.1 + both
# converter plugins), seeds a fixture page and converts it. Idempotent.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../../" && pwd)
WP_URL=http://localhost:8020
ADMIN_USER=admin
ADMIN_PASS=admin
ADMIN_EMAIL=admin@example.test

for f in Divi.zip js_composer.9.0.1.zip; do
  if [ ! -f "$ROOT/references/$f" ]; then
    echo "Missing references/$f — see references/README.md" >&2
    exit 1
  fi
done

cd "$ROOT"
echo "Starting Docker environment..."
docker compose up -d

echo "Waiting for WordPress to answer..."
until curl -sSf "$WP_URL" >/dev/null 2>&1 || curl -sS -o /dev/null -w '%{http_code}' "$WP_URL" 2>/dev/null | grep -qE '^(200|302)$'; do
  printf '.'; sleep 2
done
echo

WP=$(docker compose ps -q wordpress)
run() { docker exec -i "$WP" bash -lc "$1"; }

echo "Installing WP-CLI inside the container..."
run "command -v wp >/dev/null 2>&1 || (curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp)"
run "command -v unzip >/dev/null 2>&1 || (apt-get update -qq >/dev/null && apt-get install -y -qq unzip >/dev/null)"

echo "Installing WordPress core..."
run "wp core is-installed --allow-root || wp core install --url=$WP_URL --title='WPBakery to Divi 5' --admin_user=$ADMIN_USER --admin_password=$ADMIN_PASS --admin_email=$ADMIN_EMAIL --skip-email --allow-root"
# Re-assert the password: long-lived containers drift, and the e2e specs hard-code it.
run "wp user update 1 --user_pass=$ADMIN_PASS --allow-root >/dev/null"
run "wp option update permalink_structure '/%postname%/' --allow-root >/dev/null"

echo "Installing Divi 5..."
# --force so a newer references/Divi.zip replaces an older installed Divi.
run "wp theme install /tmp/Divi.zip --force --allow-root"
run "wp theme activate Divi --allow-root"

echo "Installing WPBakery Page Builder..."
run "wp plugin is-installed js_composer --allow-root || wp plugin install /tmp/js_composer.zip --allow-root"
run "wp plugin activate js_composer --allow-root"

# A real WPBakery add-on, so the e2e suite has a theme element this converter
# has no handler for whose own plugin can still render it — the static-copy
# path (docs/conversion-workflow.md). Ronneby Core cannot play that part: it
# disables itself unless the DFD Ronneby theme is the active one, and Divi is.
# Only the one module the suite uses is switched on, through the plugin's own
# module option; the rest stay off.
if [ -f "$ROOT/references/Ultimate_VC_Addons.zip" ]; then
  echo "Installing Ultimate Addons for WPBakery (theme-element fixture)..."
  run "wp plugin is-installed Ultimate_VC_Addons --allow-root || wp plugin install /tmp/ultimate-vc-addons.zip --allow-root"
  run "wp plugin activate Ultimate_VC_Addons --allow-root"
  run "wp option update ultimate_modules '[\"ultimate_headings\"]' --format=json --allow-root >/dev/null"
else
  echo "(references/Ultimate_VC_Addons.zip not present — the static-copy e2e will skip)"
fi

echo "Installing Plugin Check (PCP)..."
run "wp plugin is-installed plugin-check --allow-root || wp plugin install plugin-check --allow-root"
run "wp plugin activate plugin-check --allow-root"

echo "Activating the converter plugin..."
run "wp plugin activate jhmg-converter-for-wpbakery-to-divi-5 --allow-root"
# Pro is mounted and installable, but left switched off: the e2e suite proves
# the free plugin's own surface. Switch it on by hand to work on Pro.
run "wp plugin deactivate jhmg-converter-for-wpbakery-to-divi-pro --allow-root || true"

echo "Copying WP-CLI helper scripts..."
for s in set-wpbakery-content.php set-divi-content.php import-wpb-template.php convert-run.php convert-to-new-page.php parser-parity.php; do
  docker cp "$ROOT/scripts/docker/$s" "$WP:/tmp/$s"
done

# One of WPBakery's own bundled templates, through the pipeline the Tools
# screen uses. A real template rather than the box-model fixture: the box model
# is measured by its own opt-in spec, which builds its pages itself.
echo "Seeding and converting a WPBakery template page..."
PAGE_ID=$(run "wp post list --post_type=page --name=wpbakery-source --field=ID --allow-root" | sed -n 1p)
if [ -z "$PAGE_ID" ]; then
  PAGE_ID=$(run "TEMPLATE=about-section POST_NAME=wpbakery-source wp eval-file /tmp/import-wpb-template.php --allow-root")
else
  run "FIXTURE=wpbakery-templates/about-section PAGE_ID=$PAGE_ID wp eval-file /tmp/set-wpbakery-content.php --allow-root" >/dev/null
  run "wp post update $PAGE_ID --post_title='About Section (WPBakery)' --allow-root" >/dev/null
fi
# A second run reuses the page the first one produced rather than piling up
# another copy: the converter stamps `_wbdc_source_post_id` on everything it
# creates, so the seed can ask whether this source has been converted already.
NEW_ID=$(run "wp post list --post_type=page --post_status=any --meta_key=_wbdc_source_post_id --meta_value=$PAGE_ID --field=ID --allow-root" | sed -n 1p)
if [ -z "$NEW_ID" ]; then
  # The converter is built, so a conversion that fails here is a real failure:
  # the helper prints why on stderr and this stops rather than seeding a site
  # whose "converted" page does not exist.
  if ! NEW_ID=$(run "SOURCE_PAGE_ID=$PAGE_ID wp eval-file /tmp/convert-to-new-page.php --allow-root"); then
    echo "Converting the seeded fixture failed (message above)." >&2
    exit 1
  fi
fi

# One real theme export, through the plugin's own WXR import path, so the site
# holds a page nobody wrote for this converter. `ten-layout` is the slug the
# export itself carries.
RONNEBY_ID=""
RONNEBY_XML=/var/www/html/wpbakery-templates/ronneby/15_tenth.xml
if run "test -r $RONNEBY_XML"; then
  echo "Importing a real Ronneby export (15_tenth.xml)..."
  RONNEBY_ID=$(run "wp post list --post_type=page --post_status=any --name=ten-layout --field=ID --allow-root" | sed -n 1p)
  if [ -z "$RONNEBY_ID" ]; then
    if ! RONNEBY_ID=$(run "WXR=$RONNEBY_XML wp eval-file /tmp/import-wpb-template.php --allow-root"); then
      echo "Importing the Ronneby export failed (message above)." >&2
      exit 1
    fi
  fi
else
  echo "(wpbakery templates/ronneby/15_tenth.xml is not mounted — skipping the export import)"
fi

echo
echo "Setup complete."
echo "  Site:            $WP_URL  (admin: $ADMIN_USER / $ADMIN_PASS)"
echo "  Converter:       $WP_URL/wp-admin/tools.php?page=wbdc-converter"
echo "  Source page:     $WP_URL/?page_id=$PAGE_ID"
echo "  Converted page:  $WP_URL/?page_id=$NEW_ID"
if [ -n "$RONNEBY_ID" ]; then
  echo "  Ronneby export:  $WP_URL/?page_id=$RONNEBY_ID"
fi
echo "  WordPress $(run 'wp core version --allow-root') · Divi $(run "wp theme get Divi --field=version --allow-root") · WPBakery $(run "wp plugin get js_composer --field=version --allow-root")"

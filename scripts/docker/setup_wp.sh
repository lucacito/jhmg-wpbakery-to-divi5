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

echo "Installing Plugin Check (PCP)..."
run "wp plugin is-installed plugin-check --allow-root || wp plugin install plugin-check --allow-root"
run "wp plugin activate plugin-check --allow-root"

echo "Activating the converter plugins..."
run "wp plugin activate jhmg-converter-for-wpbakery-to-divi --allow-root"
run "wp plugin activate jhmg-converter-for-wpbakery-to-divi-pro --allow-root || true"

echo "Copying WP-CLI helper scripts..."
for s in set-wpbakery-content.php set-divi-content.php import-wpb-template.php convert-run.php convert-to-new-page.php; do
  docker cp "$ROOT/scripts/docker/$s" "$WP:/tmp/$s"
done

echo "Seeding and converting a fixture page..."
PAGE_ID=$(run "wp post list --post_type=page --name=wpbakery-source --field=ID --allow-root" | sed -n 1p)
if [ -z "$PAGE_ID" ]; then
  PAGE_ID=$(run "wp post create --post_type=page --post_status=publish --post_name=wpbakery-source --post_title='Box Model (WPBakery)' --porcelain --allow-root")
fi
run "FIXTURE=wpbakery/box-model PAGE_ID=$PAGE_ID wp eval-file /tmp/set-wpbakery-content.php --allow-root"
# The converter is built, so a conversion that fails here is a real failure:
# the helper prints why on stderr and this stops rather than seeding a site
# whose "converted" page does not exist.
if ! NEW_ID=$(run "SOURCE_PAGE_ID=$PAGE_ID wp eval-file /tmp/convert-to-new-page.php --allow-root"); then
  echo "Converting the seeded fixture failed (message above)." >&2
  exit 1
fi

echo
echo "Setup complete."
echo "  Site:            $WP_URL  (admin: $ADMIN_USER / $ADMIN_PASS)"
echo "  Converter:       $WP_URL/wp-admin/tools.php?page=wbdc-converter"
echo "  Source page:     $WP_URL/?page_id=$PAGE_ID"
echo "  Converted page:  $WP_URL/?page_id=$NEW_ID"
echo "  WordPress $(run 'wp core version --allow-root') · Divi $(run "wp theme get Divi --field=version --allow-root") · WPBakery $(run "wp plugin get js_composer --field=version --allow-root")"

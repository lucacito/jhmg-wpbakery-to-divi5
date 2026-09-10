#!/bin/bash
set -e
echo "Running PHP tests..."
vendor/bin/phpunit

# Parser parity needs WordPress itself, so it only runs when the Docker site is
# up; a developer without it still gets PHPUnit and is told what was skipped.
CONTAINER=$(docker compose ps -q wordpress 2>/dev/null || true)
if [ -n "$CONTAINER" ] && [ -n "$(docker ps -q --filter "id=$CONTAINER" 2>/dev/null)" ]; then
  echo "Checking the shortcode parser against WordPress's own regex..."
  docker cp scripts/docker/parser-parity.php "$CONTAINER:/tmp/parser-parity.php" >/dev/null
  docker exec -i "$CONTAINER" bash -lc "wp eval-file /tmp/parser-parity.php --allow-root"
else
  echo "Skipping parser parity: the WordPress container is not running (scripts/docker/setup_wp.sh)."
fi

echo "Running browser tests..."
npx playwright test

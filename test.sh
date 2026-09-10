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
  # A plugin on the site can be noisy with PHP deprecations that say nothing
  # about the parser (WP_DEBUG makes WordPress report them and WP-CLI displays
  # them), so a passing step drops those lines and a failing one shows its
  # stderr in full. The step's own exit status is what decides.
  parity_stderr=$(mktemp)
  if docker exec -i "$CONTAINER" bash -lc "wp eval-file /tmp/parser-parity.php --allow-root" 2>"$parity_stderr"; then
    grep -v '^Deprecated: ' "$parity_stderr" >&2 || true
    rm -f "$parity_stderr"
  else
    parity_status=$?
    cat "$parity_stderr" >&2
    rm -f "$parity_stderr"
    exit $parity_status
  fi
else
  echo "Skipping parser parity: the WordPress container is not running (scripts/docker/setup_wp.sh)."
fi

echo "Running browser tests..."
npx playwright test

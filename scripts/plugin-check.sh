#!/usr/bin/env bash
# Runs WordPress's Plugin Check (PCP) against both converter plugins inside the
# Docker site. Requires scripts/docker/setup_wp.sh to have run (it installs PCP).
# Usage: scripts/plugin-check.sh [free|pro|all] [extra wp plugin check args…]
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
which=${1:-all}; shift || true
FREE=jhmg-converter-for-wpbakery-to-divi
PRO=jhmg-converter-for-wpbakery-to-divi-pro
case "$which" in
  free) plugins=("$FREE") ;;
  pro)  plugins=("$PRO") ;;
  all)  plugins=("$FREE" "$PRO") ;;
  *)    echo "usage: $0 [free|pro|all] [wp plugin check args]" >&2; exit 2 ;;
esac
status=0
for p in "${plugins[@]}"; do
  echo "### $p"
  docker compose exec -T -e PAGER=cat -e WP_CLI_PAGER=cat wordpress \
    wp plugin check "$p" --format=table --allow-root "$@" </dev/null || status=$?
  echo
done
exit $status

#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 1 ]]; then
  printf 'Usage: %s <exact-plugin.zip>\n' "$0" >&2
  exit 2
fi
plugin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
root_dir="$plugin_dir"
archive="$(realpath "$1")"
test_dir="$root_dir/tools/wordpress-test"
project="digicyfr-embed-contract-$$"
port="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')"
export DIGICYFR_TEST_PORT="$port"
docker_cmd=(docker)
compose_cmd=(env "DIGICYFR_TEST_PORT=$port" docker)
if ! docker info >/dev/null 2>&1; then docker_cmd=(sudo -n docker); compose_cmd=(sudo -n env "DIGICYFR_TEST_PORT=$port" docker); fi
python3 "$root_dir/tools/verify_zip.py" "$archive" --slug digicyfr-external-embed-inventory --against-source
cleanup() { "${compose_cmd[@]}" compose -p "$project" -f "$test_dir/compose.yaml" down --volumes --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
"${compose_cmd[@]}" compose -p "$project" -f "$test_dir/compose.yaml" up -d --wait
container="$("${compose_cmd[@]}" compose -p "$project" -f "$test_dir/compose.yaml" ps -q wordpress)"
for _ in {1..90}; do
  if "${docker_cmd[@]}" exec "$container" test -f /var/www/html/wp-load.php; then break; fi
  sleep 1
done
"${docker_cmd[@]}" exec "$container" test -f /var/www/html/wp-load.php
"${docker_cmd[@]}" cp "$archive" "$container:/tmp/digicyfr-plugin.zip" >/dev/null
"${docker_cmd[@]}" cp "$test_dir/install-site.php" "$container:/tmp/digicyfr-install-site.php" >/dev/null
"${docker_cmd[@]}" cp "$test_dir/smoke-zip.php" "$container:/tmp/digicyfr-smoke.php" >/dev/null
"${docker_cmd[@]}" cp "$plugin_dir/tests/wp-contract.php" "$container:/tmp/deei-contract.php" >/dev/null
"${docker_cmd[@]}" exec -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-install-site.php
"${docker_cmd[@]}" exec -e "DIGICYFR_PLUGIN_SLUG=digicyfr-external-embed-inventory" -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-smoke.php
"${docker_cmd[@]}" exec "$container" mkdir -p /var/www/html/wp-content/mu-plugins
"${docker_cmd[@]}" cp "$plugin_dir/tests/force-pl-locale.php" "$container:/var/www/html/wp-content/mu-plugins/deei-test-locale.php" >/dev/null
"${docker_cmd[@]}" exec -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/deei-contract.php

#!/usr/bin/env bash
# Install a throwaway WordPress with the plugin activated, for the e2e tests.
#
#   WP_PATH      where to install (default /tmp/finb-wp)
#   WP_URL       site URL (default http://127.0.0.1:8080)
#   DB_*         MySQL/MariaDB connection
#   MOCK_URL     the mock financing platform (default http://127.0.0.1:8099)
#   WP_SOURCE    optional: copy core from this directory instead of downloading
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_PATH="${WP_PATH:-/tmp/finb-wp}"
WP_URL="${WP_URL:-http://127.0.0.1:8080}"
MOCK_URL="${MOCK_URL:-http://127.0.0.1:8099}"
DB_NAME="${DB_NAME:-wp}" DB_USER="${DB_USER:-wp}" DB_PASS="${DB_PASS:-wp}" DB_HOST="${DB_HOST:-127.0.0.1}"
wp() { command wp --path="$WP_PATH" --allow-root --quiet "$@"; }

rm -rf "$WP_PATH" && mkdir -p "$WP_PATH"
if [ -n "${WP_SOURCE:-}" ]; then
  cp -r "$WP_SOURCE"/. "$WP_PATH"/ && rm -rf "$WP_PATH/.git"
else
  wp core download --version="${WP_VERSION:-latest}"
fi

wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check
wp db reset --yes 2>/dev/null || wp db create
wp config set FINB_API_BASE "$MOCK_URL"
wp config set FINB_API_KEY "test-api-key-not-a-real-secret"
wp config set FINB_WEBHOOK_SECRET "whsec_$(printf 'x%.0s' $(seq 40))"
wp config set WP_ENVIRONMENT_TYPE local
wp core install --url="$WP_URL" --title="Financing Bridge lab" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email

ln -sfn "$ROOT/plugin/financing-bridge" "$WP_PATH/wp-content/plugins/financing-bridge"
wp plugin activate financing-bridge
echo "WordPress ready at $WP_URL ($WP_PATH) with financing-bridge active"

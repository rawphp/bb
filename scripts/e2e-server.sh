#!/usr/bin/env bash
# The app for tests/e2e (playwright.config.ts starts it): the built front end from public/build,
# a throwaway MySQL 8 with the area seeders and Tests\Support\AuditSeeder, and no Redis.
# mysqld is found as Warp finds it: WARP_DB_MYSQLD, else mysqld on PATH.
# Usage: scripts/e2e-server.sh <port>   (MySQL listens on <port> + 1)
set -euo pipefail
cd "$(dirname "$0")/.."

port="$1"
db_port=$((port + 1))
mysqld="${WARP_DB_MYSQLD:-$(command -v mysqld || true)}"
[ -x "$mysqld" ] || { echo "e2e: mysqld not found; install MySQL 8 or set WARP_DB_MYSQLD" >&2; exit 1; }
bin="$(dirname "$mysqld")"
data="$PWD/storage/framework/testing/e2e-mysql"
# A short socket path: macOS caps a socket path at 104 bytes.
socket="/tmp/bb-e2e-$db_port.sock"

# The php shim forks, and php -S forks its workers, so the server is stopped as a tree.
killtree() {
  local child
  for child in $(pgrep -P "$1" || true); do killtree "$child"; done
  kill "$1" 2>/dev/null || true
}

stop() {
  [ -n "${server:-}" ] && killtree "$server"
  if [ -f "$data/mysqld.pid" ]; then kill "$(cat "$data/mysqld.pid")" 2>/dev/null || true; fi
  [ -n "${database:-}" ] && wait "$database" 2>/dev/null || true
  rm -rf "$data" "$socket" "$socket.lock"
}
trap stop EXIT
trap 'exit 143' TERM INT

# A run killed hard leaves its MySQL here; this script started it, so it may stop it.
if [ -f "$data/mysqld.pid" ]; then kill "$(cat "$data/mysqld.pid")" 2>/dev/null || true; sleep 2; fi
rm -rf "$data" "$socket" "$socket.lock" storage/framework/testing/e2e.json

"$mysqld" --no-defaults --initialize-insecure --datadir="$data" --log-error="$data.init.log" >/dev/null 2>&1
rm -f "$data.init.log"
"$mysqld" --no-defaults --datadir="$data" --socket="$socket" --port="$db_port" --bind-address=127.0.0.1 \
  --mysqlx=OFF --skip-log-bin --innodb-redo-log-capacity=8M --pid-file="$data/mysqld.pid" \
  --log-error="$data/error.log" &
database=$!
for _ in $(seq 1 60); do "$bin/mysqladmin" --no-defaults -uroot -S "$socket" ping >/dev/null 2>&1 && break; sleep 0.5; done
"$bin/mysql" --no-defaults -uroot -S "$socket" -e 'CREATE DATABASE bodybuilder'

export APP_ENV=local APP_DEBUG=false APP_URL="http://127.0.0.1:$port"
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$db_port" DB_DATABASE=bodybuilder DB_USERNAME=root DB_PASSWORD=
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync MAIL_MAILER=array BILLING_ENABLED=false
# A week: the file session's expiry reads the request clock, so a clock walked days ahead (X-BB-Now) would sign out.
export SESSION_LIFETIME=10080
# Rate-limit counters live for one request: a scripted user answers onboarding faster than the 30-a-minute limit.
export CAPABILITIES_RATE_LIMITS_DRIVER=memory

php artisan migrate --seed --force --no-interaction >/dev/null
php artisan db:seed --class='Tests\Support\AuditSeeder' --force --no-interaction >/dev/null

# php -S directly, not artisan serve, which drops these variables and reads .env again.
# Laravel's router script serves from its working directory, so it runs in public/.
(cd public && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$port" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) &
server=$!
wait "$server"

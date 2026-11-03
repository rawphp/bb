#!/usr/bin/env bash
# The app for tests/e2e (playwright.config.ts starts it): the built front end from public/build,
# a fresh SQLite database with the area seeders and Tests\Support\AuditSeeder, and no Redis.
# Usage: scripts/e2e-server.sh <port>
set -euo pipefail
cd "$(dirname "$0")/.."

export APP_ENV=local APP_DEBUG=false APP_URL="http://127.0.0.1:$1"
export DB_CONNECTION=sqlite DB_DATABASE="$PWD/storage/framework/testing/e2e.sqlite"
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync MAIL_MAILER=array BILLING_ENABLED=false

rm -f "$DB_DATABASE" storage/framework/testing/e2e.json
touch "$DB_DATABASE"
php artisan migrate --seed --force --no-interaction >/dev/null
php artisan db:seed --class='Tests\Support\AuditSeeder' --force --no-interaction >/dev/null

# php -S directly, not artisan serve, which drops these variables and reads .env again.
# Laravel's router script serves from its working directory, so it runs in public/.
cd public
PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$1" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php

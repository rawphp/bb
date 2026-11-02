# Self-hosting

Bodybuilder is one Laravel app that serves the PWA, holds every rule and all data, and runs the optional agent. A self-hosted instance runs the same code as the hosted version, with billing off.

Some pieces below (Web Push, food import, billing) land in later milestones. The keys and commands are listed here so an instance can be set up once.

## Requirements

- PHP 8.5 (8.4 works; `composer.json` requires `^8.4`), with the `pdo_mysql`, `redis`, `mbstring`, `intl`, `bcmath` and `gmp` extensions. Web Push needs `gmp` or `bcmath`.
- Composer 2.
- MySQL 8.
- Redis. It holds the cache, sessions, the queues and the agent's progress store.
- Node.js and npm, to build the front end.
- A web server (nginx or Caddy) with PHP-FPM, serving `public/` over HTTPS. A PWA only installs, and Web Push only works, over HTTPS.
- A process supervisor (systemd or Supervisor) for the two queue workers.
- Cron, for the scheduler.

## Install

```bash
git clone https://github.com/rawphp/bb.git bodybuilder
cd bodybuilder
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` (see [Environment](#environment)), then:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
```

Once the repo has a `package.json` (the front end lands in a later milestone), build it too:

```bash
npm ci
npm run build
```

Run `config:cache` again after every change to `.env`.

## Environment

Every key below is in `.env.example`. Keys not listed here keep their defaults.

### App

| Key | Value |
|---|---|
| `APP_NAME` | The name shown in the app. |
| `APP_ENV` | `production` |
| `APP_KEY` | Set by `php artisan key:generate`. |
| `APP_DEBUG` | `false` |
| `APP_URL` | The public HTTPS URL, for example `https://bodybuilder.example.com`. |

### MySQL

| Key | Value |
|---|---|
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT` | The MySQL server. |
| `DB_DATABASE` | An empty database, `utf8mb4`. |
| `DB_USERNAME`, `DB_PASSWORD` | A user with full rights on that database. |

### Redis

| Key | Value |
|---|---|
| `REDIS_CLIENT` | `phpredis` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | The Redis server. |
| `CACHE_STORE` | `redis` |
| `SESSION_DRIVER` | `redis` |
| `QUEUE_CONNECTION` | `redis` |

### Mail

Mail sends one-time sign-in codes and password resets, so sign-in by code does not work without it. The default `log` mailer only writes mail to the log.

| Key | Value |
|---|---|
| `MAIL_MAILER` | `smtp`, or another Laravel mailer. |
| `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT` | The SMTP server. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP credentials. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | The sender. |

### Web Push (VAPID)

The server sends reminders with Web Push, signed with a VAPID key pair. Generate the pair once and keep it: changing it drops every existing push subscription.

```bash
php artisan webpush:vapid
```

That writes the keys to `.env`. To make them elsewhere, `npx web-push generate-vapid-keys` prints a pair.

| Key | Value |
|---|---|
| `VAPID_PUBLIC_KEY` | The public key. |
| `VAPID_PRIVATE_KEY` | The private key. Keep it secret. |
| `VAPID_SUBJECT` | A contact for push services: `mailto:you@example.com` or your `APP_URL`. |

With the keys empty, the app offers no notifications.

### Agent (optional)

The in-app Coach runs on Anthropic. It is on only when the driver is `anthropic` and the API key is set (AGT-002). To run without it, set the driver and leave the key empty; no screen mentions the Coach then.

| Key | Value |
|---|---|
| `CAPABILITIES_AI_LLM_DRIVER` | `anthropic`. `.env.example` ships `fake`, which is for the test suite only: outside tests the Coach reports itself off and the chat refuses messages. Local Coach work needs `anthropic` and a key too. |
| `ANTHROPIC_API_KEY` | Your Anthropic API key to turn the Coach on, or empty to leave it off. Every user's Coach turns are billed to it. |
| `AGENT_TURNS_PER_DAY` | Coach messages each user may send per day (AGT-005). Empty for no cap. |
| `AGENT_TOKENS_PER_MONTH` | Anthropic tokens each user's Coach turns may use per month. Empty for no cap. With a cap, a user has one Coach turn at a time (checked under a per-user lock in the cache), and a turn that starts under the cap finishes, so a month can end at most one turn over. |

### Billing (optional)

Billing is off unless `BILLING_ENABLED=true` (SUB-020). A self-hosted instance leaves it off: every feature is free to every user and no screen mentions a subscription. The Stripe keys are read only when billing is on.

| Key | Value |
|---|---|
| `BILLING_ENABLED` | `false` |
| `STRIPE_KEY` | Stripe publishable key. |
| `STRIPE_SECRET` | Stripe secret key. |
| `STRIPE_WEBHOOK_SECRET` | The signing secret of the Stripe webhook endpoint. The server trusts subscription state only from webhooks it can verify (SUB-021). |

With billing on, add a webhook endpoint in Stripe pointing at the app's Stripe webhook route (`php artisan route:list` shows it).

## Queue workers

Two workers:

- `default`: the `default` queue (notifications, mail and other background jobs) and the `capabilities-audit` queue, which writes the audit log.
- `agent`: the Coach's turns, on the `agent` connection. Its `retry_after` is `CAPABILITIES_AI_CLAIM_TTL` plus 60 seconds, so a turn that runs long is never handed to a second worker. Only needed with the agent on, but harmless without it.

```bash
php artisan queue:work redis --queue=default,capabilities-audit --tries=3
php artisan queue:work agent --queue=agent --tries=1
```

Keep both running under your supervisor. A systemd unit for the `default` worker:

```ini
[Unit]
Description=Bodybuilder default queue worker
After=network.target mysql.service redis.service

[Service]
User=www-data
WorkingDirectory=/var/www/bodybuilder
ExecStart=/usr/bin/php artisan queue:work redis --queue=default,capabilities-audit --tries=3
Restart=always

[Install]
WantedBy=multi-user.target
```

Copy it for `agent` with `queue:work agent --queue=agent --tries=1`. Restart both workers after every deploy (`php artisan queue:restart`), or they keep running the old code.

## Scheduler

The scheduler sends due Web Push notifications, reaps the agent's stale turns and prunes the idempotency and audit tables. Run it every minute from cron, as the web server user:

```cron
* * * * * cd /var/www/bodybuilder && php artisan schedule:run >> /dev/null 2>&1
```

## Food data

The food database is imported at install time; no dataset is in the repo. Download the dumps, then import each one:

```bash
php artisan foods:import usda FoodData_Central_foundation_food_json.json
php artisan foods:import usda FoodData_Central_branded_food_json.json
php artisan foods:import off openfoodfacts-products.jsonl.gz
```

- USDA FoodData Central (public domain): the Foundation and Branded JSON downloads from <https://fdc.nal.usda.gov/download-datasets>.
- Open Food Facts (ODbL): the JSONL export from <https://world.openfoodfacts.org/data>. The app shows the ODbL attribution. Any dump you publish that is derived from it stays under ODbL.

Imports stream the file and upsert in batches, so re-running one leaves the same rows. Re-run them to refresh the data.

## Updating

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

With a `package.json` in the repo, rebuild the front end too (`npm ci`, then `npm run build`).

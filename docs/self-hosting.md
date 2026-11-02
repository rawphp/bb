# Self-hosting

Bodybuilder is one Laravel app that serves the PWA, holds every rule and all data, and runs the optional agent. A self-hosted instance runs the same code as the hosted version, with billing off.

Some pieces below (food import, billing) land in later milestones. The keys and commands are listed here so an instance can be set up once.

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
| `AGENT_TURNS_PER_DAY` | Coach messages each user may send per day (AGT-005). Empty for no cap. For either cap, a value that is not a whole number stops the app from booting. Either cap is checked under a per-user cache lock, so keep `CACHE_STORE=redis` with more than one app server (the `array` store does not lock across requests at all). |
| `AGENT_TOKENS_PER_MONTH` | Anthropic tokens each user's Coach turns may use per month. Empty for no cap. With a cap, a user has one Coach turn at a time, and a turn that starts under the cap finishes, so a month can end at most one turn over. A turn whose worker is killed records no usage, so its tokens are not counted. |

### Billing (optional)

Billing is off unless `BILLING_ENABLED=true` (SUB-020). A self-hosted instance leaves it off: every feature is free to every user, no screen mentions a subscription, and no billing capability or webhook route exists. The Stripe settings are read only when billing is on. With it on, a missing secret, or no price at all, stops the app from booting.

| Key | Value |
|---|---|
| `BILLING_ENABLED` | `false`, or `true` to sell subscriptions (`1`/`0`, `yes`/`no` and `on`/`off` work too). Any other value stops the app from booting. |
| `STRIPE_KEY` | Stripe publishable key. Not used yet: payments open on Stripe's own Checkout page. |
| `STRIPE_SECRET` | Stripe secret key. |
| `STRIPE_WEBHOOK_SECRET` | The signing secret of the Stripe webhook endpoint. The server reads an event only when it can verify the signature, and takes subscription state from Stripe, never from the browser (SUB-021). |
| `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_SIX_MONTHS`, `STRIPE_PRICE_YEARLY` | The Stripe price ID of each plan (SUB-002): a recurring price of 1 month, 6 months and 1 year. The paywall shows Stripe's amount and currency, read every 15 minutes by the scheduler (see Scheduler; `php artisan billing:refresh-prices` reads them at once). Leave one empty to not sell that plan. |

With billing on:

- Add a webhook endpoint in Stripe at `https://<your domain>/stripe/webhook`, sending `customer.subscription.created`, `customer.subscription.updated` and `customer.subscription.deleted`. On each event the app reads the customer's subscriptions back from Stripe, so events arriving out of order do no harm. If Stripe cannot be reached, the webhook fails and Stripe sends the event again.
- In Stripe's Checkout settings, turn on "Limit customers to one subscription" as a second guard. The app already refuses Checkout to a user with a live subscription, and closes the last Checkout page it opened before it opens another, so only one can be paid.
- Turn on the customer portal in Stripe's settings, with plan switching between the prices above and cancelling (SUB-007).
- Sale, referral and gym prices are Stripe coupons with promotion codes; users type the code in Checkout (SUB-009).
- A user who has never subscribed gets a 7-day free trial in Checkout (SUB-003). There is no free tier: until the trial starts, they can answer onboarding and see the paywall, but make no other change and get no plan. Turning billing on for an instance that already has users puts each of them there too: their data is kept and read-only until they start their trial. A user whose subscription has ended keeps every screen read-only (SUB-010) and cannot talk to the Coach until they resubscribe (SUB-024). They can still delete the account, revoke an access token and turn off push (SUB-022).

## Queue workers

Two workers:

- `default`: the `default` queue (notifications, mail and other background jobs). The audit log needs no worker: each row is written during the request that made the change. Earlier versions of this guide also listed a `capabilities-audit` queue. Nothing was ever pushed to it, so an upgrade has nothing to drain there. A failed audit insert is reported to the app's exception handler (your error tracker or `storage/logs`).
- `agent`: the Coach's turns, on the `agent` connection. Its `retry_after` is `CAPABILITIES_AI_CLAIM_TTL` plus 60 seconds, so a turn that runs long is never handed to a second worker. Only needed with the agent on, but harmless without it.

```bash
php artisan queue:work redis --queue=default --tries=3
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
ExecStart=/usr/bin/php artisan queue:work redis --queue=default --tries=3
Restart=always

[Install]
WantedBy=multi-user.target
```

Copy it for `agent` with `queue:work agent --queue=agent --tries=1`. Restart both workers after every deploy (`php artisan queue:restart`), or they keep running the old code.

## Scheduler

The scheduler reaps the agent's stale turns, prunes the audit log (`audit_records`, kept 90 days) and prunes expired undo tokens. With billing on it also reads the paywall's prices from Stripe every 15 minutes (`billing:refresh-prices`). Without cron, the paywall reads a price from Stripe itself when its kept copy is more than 15 minutes old, so a paywall request can wait on Stripe now and then. With the VAPID keys set, it runs `reminders:send` every minute, which queues each subscribed user's due reminders (weigh-ins, meals, workouts, the weekly review and the next mesocycle) on the `default` queue; each is sent once, and daily the log of sent reminders is pruned to two days. Reminder times are read as UTC. Run the scheduler every minute from cron, as the web server user:

```cron
* * * * * cd /var/www/bodybuilder && php artisan schedule:run >> /dev/null 2>&1
```

An instance that ran before the audit log moved to `audit_records` also has audit rows in the package's `capabilities_audit_outbox` table. Nothing writes to it any more. The same daily prune keeps those rows to 90 days too, and deleting an account deletes its rows there.

## Food data

The food database is imported at install time; no dataset is in the repo. Download the dumps, then import each one:

```bash
php artisan foods:import usda FoodData_Central_foundation_food_json.json
php artisan foods:import usda FoodData_Central_branded_food_json.json
php artisan foods:import off openfoodfacts-products.jsonl.gz
```

- USDA FoodData Central (public domain): the Foundation and Branded JSON downloads from <https://fdc.nal.usda.gov/download-datasets>.
- Open Food Facts (ODbL): the JSONL export from <https://world.openfoodfacts.org/data>. ODbL requires the credit "Food data from Open Food Facts (openfoodfacts.org), available under the Open Database License (ODbL)." in the app. The app does not show it yet: the food detail and Settings → About screens that will are not built. Until they ship, show that text yourself wherever your instance shows food data; `foods:import off` prints this reminder after every successful import. Any dump you publish that is derived from it stays under ODbL.

Imports stream the file and upsert in batches, so re-running one leaves the same rows. An import that finds the file cut off or corrupt exits with an error, keeping the foods it saved before the bad part (a cut-off USDA file loses the batch it was reading): download the file again and re-run it. Import the Open Food Facts `.jsonl.gz` as downloaded rather than unpacking it, since a plain `.jsonl` cut exactly at a line break looks complete. Re-run them to refresh the data.

An import writes `foods` and `food_servings` and, on a re-run, replaces imported foods' values and servings; removing the command does not undo that. On an instance with users, take a full database backup before an import. Restoring it takes the import back out but also discards everything users wrote after the backup, so do it only right after a failed or unwanted import, before users log against the new foods. Later, fix bad data by re-importing a corrected file, which refreshes the same rows in place. Do not delete imported foods by hand: users' favorites, recents, saved meals, plans, recipes and logged entries point at them, so a delete cascades into user data or is refused by a foreign key.

## Updating

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

`db:seed` brings the library (exercises, templates, curated foods, recipes) up to the data files in `database/seeders/data/`, so a corrected value there reaches an install that is already running. It is safe to re-run: library rows are matched by name or slug and updated in place, so their IDs and the users' meals, saved meals and recipes that point at them are kept. Nothing is duplicated, and the test account is only ever seeded in `local` and `testing`. `tests/Feature/DatabaseSeederTest.php` re-runs every seeder on a production install with user data. It fails if any row in a non-library table changes, if a user's own food, recipe or exercise changes, or if a library row's ID changes. Take a database backup before updating, as for any release.

With a `package.json` in the repo, rebuild the front end too (`npm ci`, then `npm run build`).

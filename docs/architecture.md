# Architecture

Decisions every issue follows. The product spec is `docs/spec/`; requirement IDs (for example CAP-001) refer to it.

## One sentence

A Laravel 13 app owns every rule and exposes every action as a capability. A Vue 3 PWA calls those capabilities and only displays. The optional agent calls the same capabilities in-process.

```text
 Vue PWA ──► outbox / cache ──► POST /capabilities/{name} ─┐
 MCP client / CLI / Shortcut ─► POST /capabilities/{name} ─┤  (token)
 In-app agent (TurnRunner) ──► CapabilityBus::invoke ──────┤  (caller=job, actor=user)
                                                           ▼
                        CapabilityRegistry → authorize (CAP-020) → run() → audit
                                                           │
                                                 app/Domain rules + MySQL
```

## Platforms

- PWA, phone first: iOS 17.4+ Safari installed to the home screen, Android Chrome 120+, desktop browsers in a centred phone column (PLT-001, PLT-002).
- No native shell in v1.
- The same code runs self-hosted (billing off) and as the paid hosted version (billing on). Hosted-only behaviour is configuration (SUB-023).

## Layout

One Laravel app at the repo root. There is no `server/` folder and no separate front-end repo.

```text
app/
  Domain/<Module>/        rules: plain PHP, no Eloquent, no facades; unit tested
  Actions/<Module>/       one class per write: loads models, calls Domain, saves, returns the result
  Capabilities/<Module>/  input DTOs (schema) and the module's registrar
  Capabilities/Reads/     the *_show read capabilities and their payload builders
  Agent/                  tool catalog, conversation context provider, eval fixtures
  Providers/CapabilitiesHostProvider.php   registered last
resources/js/             the Vue app (19-views.md, "Layout")
routes/web.php            the app shell, sign-in, sign-out, Stripe webhook
database/                 migrations, factories, seeders (seeders replace rp-clone's debug sources)
scripts/check.sh          the gate
docs/                     spec, architecture, personas, screenshots
```

`app/Domain` is where rp-clone's `RPCore/Domain` Swift moves (19-views.md, "Rules that move to the server"). A Domain class takes values and returns values, so its tests need no database.

## Server

- Laravel 13, PHP 8.5, MySQL 8, Redis (cache, queues, the AI progress store).
- Auth: Sanctum. The PWA uses SPA cookie auth on the same domain. Tokens (CAP-021) are Sanctum personal access tokens whose abilities are capability names.
- Queues: `default` and `agent` (AGT setup defaults), each with its own worker.
- Scheduler: the Web Push sender (NOTIF), the package's stale-turn reaper, and pruning of the idempotency and audit tables.
- Web Push: VAPID keys in env, sent with `laravel-notification-channels/webpush`. The server decides when each notification is due.
- Mail: one-time sign-in codes, password reset.
- Every table holding user data has `user_id` as `foreignId()->constrained()->cascadeOnDelete()`, so `account_delete` removes everything (ACC-005), as in rp-clone.

## Capabilities

- Packages: `rawphp/laravel-capabilities` and `rawphp/laravel-capabilities-ai`, the same version (lockstep), required from the package repos by VCS at a tag, with exact versions in `composer.lock`. Never a path repo or `dev-main` in the committed `composer.json`. Switch to Packagist once the packages are published.
- `laravel/mcp` for product MCP (AGT-040). `laravel/ai` is not installed, and the package's `agent` surface stays off.
- Messaging (`rawphp/laravel-capabilities-messaging`) is not installed.
- One host provider, registered last. It binds the authorizer (CAP-020), the tool catalog (AGT-003) and the conversation context provider (AGT-011), and calls each module's registrar.
- Registration is fluent and explicit: each module's registrar lists its capabilities. No attribute scanning, no second discovery path. Each definition names its input DTO (the only schema source), its `run()` (which calls an Action), its surfaces, its CLI domain and verb, `affects` (CAP-011), and the flags `reversible` and `destructive`.
- Enabled surfaces: `http`, `job`, `mcp`, `cli`. The Vue app uses `http`.
- Package defaults are overridden on day one, per `mesoprep/docs/capabilities-host-lessons-learned.md` section 3, item 14: see 17-agent.md, "Setup defaults". Published config files use no `env()` calls inside closures, so `config:cache` works (lessons item 15).
- Freeze tests from day one: registration smoke (every capability registers on its declared surfaces), schema snapshots, the UI and agent parity test (CAP-030), CLI map completeness, and `php artisan capabilities:integration-health` passing in CI.
- Adding a capability: the DTO, the Action with its unit tests, the registrar line, a schema snapshot. Nothing else.

## Client

- Vue 3, TypeScript, Vue Router, Tailwind CSS 4, Vite, `vite-plugin-pwa` (Workbox, `injectManifest`), `idb` for IndexedDB. No Pinia. App state is the read cache plus the outbox, both behind one module.
- `app/call.ts` is the only way the app talks to the server. `read(name, input)` is stale-while-revalidate through the cache (OFF-003). `write(name, input)` adds to the outbox and returns once the entry is stored (OFF-006). Components never call `fetch`.
- The CAP-001 boundary is checked in review and by a lint rule: `resources/js` may not import date math beyond formatting, and no component may compute a value from two server numbers.
- Vite dev server port **5283**, recorded in `~/EA/projects/AGENTS.md`. Local site `bodybuilder.test` through Herd.

## Walking through time

Carried over from rp-clone so a tester can live through weeks in minutes. Local and testing environments only.

- The client has one clock: the device clock plus an offset kept in local storage, set from Settings → Developer (SET-011): a date picker, "+1 day", "+7 days", "Clear".
- With an offset set, every request sends `X-BB-Now: <ISO 8601>`, and outbox entries use the shifted time for `occurred_at` (OFF-007).
- `App\Http\Middleware\UseRequestClock`, first in the `api` and capability route groups, calls `Carbon::setTestNow` for that request when `APP_ENV` is `local` or `testing`. Every other environment ignores the header.
- The in-app agent's turns run on the queue, so the turn records the request's clock and the job sets it before running tools.

## Tests

The `~/EA/projects/AGENTS.md` testing rules apply: unit first, thin feature tests for HTTP and auth seams, and schema changes last.

- Pest for PHP. Domain and Actions are unit tests with mocked I/O. Feature tests cover the capability HTTP seam, cross-user 404s, token scopes, idempotency and the Stripe webhook.
- Warp (`rawphp/warp`) with `WARP_MODE` and `WARP_DB` on, against MySQL, from M0.
- Vitest for components and `call.ts` (cache, outbox order, retry, the 4xx tray, the user switch in OFF-012).
- Playwright on a 390 × 844 viewport for three journeys only:
  1. stepper onboarding to the first finished workout;
  2. the offline workout (OFF-050);
  3. chat onboarding against the fake LLM driver.
- Tests are named after the requirement they prove, for example `it('PROG-024 recalculates reps at equal effort')`.
- Agent evals (AGT-050) live in `tests/Agent/` and run against the fake driver in the gate. The real-model run is a separate command, run before a release.
- `bash scripts/check.sh` is the gate. It runs, stopping at the first failure:
  1. the spec-ID check (every requirement ID named in a test exists in `docs/spec/`);
  2. `pint --test`;
  3. `phpstan`;
  4. `pest --parallel`;
  5. `vue-tsc --noEmit`;
  6. `vitest run`;
  7. `vite build`;
  8. Playwright.

## Deployment

- The hosted version deploys tagged releases from `main` through Laravel Forge. Its env turns on billing (SUB-021), agent caps (AGT-005), Web Push and mail.
- Self-hosting: `docs/self-hosting.md` (written in M0) lists PHP, MySQL, Redis, the queue workers, the scheduler and the env keys. A Docker Compose setup is an M6 candidate, not v1.
- Production never runs with `CAPABILITIES_AI_ALLOW_UNSAFE`, the array progress driver, or the fake LLM driver.

## Open questions

| Question | Why it matters |
|---|---|
| Licence: MIT or AGPL-3.0? | With MIT, anyone may run a closed, paid copy of the hosted version. AGPL-3.0 makes anyone who hosts a modified copy publish their changes, which protects a paid hosted version, but some companies won't contribute to AGPL code. The repo has MIT today; changing later needs every contributor's agreement, so decide before the first outside pull request. |
| Food data | rp-clone imports USDA FoodData Central (public domain) and Open Food Facts (ODbL) with `foods:import`. Keep it an import at install time, not data in the repo. ODbL needs attribution in the app and keeps any published dump derived from Open Food Facts under ODbL. Confirm that suits the hosted version. |
| Learn content | rp-clone's articles paraphrase RP material. They must be in Bodybuilder's own words before the repo is public. |
| GitHub home | `rawphp/bodybuilder` is assumed. Not created yet. |

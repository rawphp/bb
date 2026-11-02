# Build plan

The manifest the factory issues are filed from. One row per issue. Bodies are expanded from this file by the filing script: the unit's own criteria, the template for its kind, and the shared notes. Review this file, not 127 issues.

- Spec: `docs/spec/` and `docs/architecture.md` at the commit that adds this file.
- Tracker: GitHub Issues on `rawphp/bb`, built by the t3fac factory (`.dw/config.yml`).
- Goal: the widest graph the spec allows. 125 issues plus one parked. The longest chain is 9 issues, and everything else runs beside it.

## How the graph stays wide

1. **No shared files after the skeleton.** Each capability is its own class file, loaded from its folder by the host provider (architecture.md, "Capabilities"). Each feature has its own Vue route file, loaded by glob. Each design system component has its own gallery file, loaded by glob. Migrations are timestamped. So no two issues below SKEL_WEB edit the same file, and none are chained for file reasons.
2. **Contracts split screens from rules.** A CONTRACT issue per area commits the output DTO and an example payload for every `*_show` in that area, and the input DTO for every write its views send. Views build against the payload; API issues prove their output matches it. Views start as soon as the design system lands, weeks before the rules they display.
3. **Rules port in four layers.** rp-clone's Swift `Domain` folders reference each other's types in one big cycle. D_CORE ports the shared value types first (`Model`, `Defaults`). After that, the function calls between folders form four clean layers (measured from the Swift source at the pinned SHA):

   | Layer | Folders |
   |---|---|
   | 1 | Safety, Shopping, Macros, Weight, Progression, MealFit, Phases, Placement, Mesocycle, Plan |
   | 2 | MealSplit (Macros), Progress (Phases), DayBalance (Macros), Energy (Macros), Recipe (MealFit) |
   | 3 | Retarget (Energy, MealSplit, Mesocycle, Placement), WeekBuilder (Energy, Macros, MealSplit, Placement, Plan) |
   | 4 | Review (Progress, WeekBuilder) |

4. **The agent lane runs from the start.** The agent's tools come from the registry, so AI_SETUP, MCP and the chat view need only the rail. Only the context, chat onboarding and evals wait for real capabilities.

Longest chains (measured from the tables below):

- SKEL_API → D_CORE → D_MACROS → D_ENERGY → D_WEEKBUILDER → A_TODAY1 → COACH_CTX → ONB_CHAT → E2E_CHAT
- SKEL_API → D_CORE → D_MACROS → D_ENERGY → D_WEEKBUILDER → D_REVIEW → A_REV2

Issues ready at each depth: 1, 6, 26, 34, 28, 20, 5, 3, 2. D_MACROS, D_ENERGY and D_WEEKBUILDER sit on both chains, so they go first when slots are scarce.

## Throughput

- The factory runs `max_concurrent_runs: 3` builds and `max_concurrent_gates: 2` gates at once (`~/.config/t3fac/factory/config.yml`). The graph allows about 40 builds at once in wave 2, so the factory's slots are the limit, not the graph. Raise `max_concurrent_runs` if the Mac and the usage limits allow it.
- A blocker clears when its issue closes, which happens when its pull request merges (`Closes #N`). The pace through the layers is the merge pace.
- At about 90 minutes a build and 3 slots, 127 issues need about 64 hours of factory time.

## Factory setup (before filing)

- `.dw/config.yml` on `main` (added with this file): instructors `tomkaczocha` and `rawphp`, `base: main`, provision `bash scripts/provision.sh`, gate `bash scripts/check.sh`, `limits: { minutes: 120, gate_retries: 2 }`, `autonomy: pr`.
- `scripts/provision.sh` and `scripts/check.sh` start as stubs that run whatever exists, so SKEL_API's own gate works on an empty repo. SKEL_API and SKEL_WEB grow them to the full gate in architecture.md, "Tests".
- Add a clone of `rawphp/bb` as a T3 project on the factory's server (`t3 project add <path> --base-dir ~/.config/t3fac`) and set its default model. Settings → Factory must list it.
- The factory login reads `rawphp/rp-clone` and `original-solutions/mesoprep` (checked 2026-11-01), so bodies cite pinned source paths and need no snapshot in this public repo.

## Shared notes (in every body's `## Notes`)

> Spec: `docs/spec/` (requirement IDs) and `docs/architecture.md` (decisions). The architecture file wins over this issue on a conflict; stop needs-info if they disagree.
>
> Sources, read-only, pinned:
> - rp-clone at `2aa1edeb1f9fd771376e1e4ec7ce6dfbe6a76566`: `gh api "repos/rawphp/rp-clone/contents/<path>?ref=2aa1edeb1f9fd771376e1e4ec7ce6dfbe6a76566" -H 'Accept: application/vnd.github.raw'`. List a folder by dropping the `Accept` header.
> - mesoprep at `eab3e6dfc8d92573adfba101df530a66f57c9a61`, same command with `repos/original-solutions/mesoprep`.
> - capability packages at tag `v0.10.0`: `rawphp/laravel-capabilities`, `rawphp/laravel-capabilities-ai`.
>
> Stop needs-info if a source is unreadable.
>
> Port rules, not code style. Swift names become PHP names. rp-clone's server classes under `server/app/Plan`, `server/app/Nutrition` and `server/app/Training` validate a plan the iPhone built. Here the server builds it, so treat them as reference for input validation only.
>
> Out of scope for every issue: deploying, Forge, production data, pushing to any other repo, editing `docs/spec/` except to fix a line this issue proves wrong (say so in the PR).

## Conventions SKEL_API and SKEL_WEB fix

Parallel issues rely on these names. They are written into architecture.md by SKEL_API.

| Thing | Path or name |
|---|---|
| Rule module | `app/Domain/<Folder>/` (rp-clone folder name), tests in `tests/Unit/Domain/<Folder>/` |
| Action | `app/Actions/<Module>/<Verb><Object>.php` |
| Capability | `app/Capabilities/<Module>/<StudlyName>Capability.php`, one per capability, loaded by `CapabilitiesHostProvider` from `app/Capabilities/*/*Capability.php` |
| DTOs | `app/Capabilities/<Module>/Data/<StudlyName>Input.php`, `...Output.php` |
| Screen payload fixture | `tests/fixtures/screens/<capability_name>.json` |
| Schema snapshot | `tests/fixtures/schemas/<capability_name>.input.json` and `.output.json` |
| TS types | generated into `resources/js/contracts/` from the schema snapshots by `npm run contracts` |
| Fixture check | Pest helper `assertMatchesScreenFixture($name, $payload)`: same keys and types at every depth, values free, and valid against the output schema snapshot |
| Feature routes | `resources/js/features/<feature>/routes.ts`, loaded by glob |
| Dev screens | `/dev/screens/<capability_name>` renders the screen from its fixture (local and testing only) |
| Gallery entries | `resources/js/design/gallery/<Component>.gallery.vue`, loaded by glob at `/dev/gallery` |
| Seeds | `database/seeders/<Area>Seeder.php`, all called from `DatabaseSeeder` by glob |
| Requirement test names | `it('NUT-003 …')` in Pest and Vitest |

## Templates by kind

**DOMAIN** (port one rp-clone `ios/Sources/RPCore/Domain/<Folder>`):
1. Every test in `ios/Tests/RPCoreTests/Domain/<Folder>` has a Pest unit test in `tests/Unit/Domain/<Folder>/` with the same requirement ID, inputs and expected values. Write it first and see it fail.
2. `app/Domain/<Folder>` holds plain PHP: an arch test fails if it uses Eloquent, a facade, `now()`, `Carbon::now()`, `config()`, or a PHP clock function, or builds a date. Time and settings arrive as arguments. Every folder's arch test takes its rules from `tests/Support/DomainArch.php`. A folder that must rebuild a date from whole values it is given names those files in its arch test; today that is only `Plan/PlanWeekGrid.php`.
3. The PR body lists each public Swift function or type and its PHP name.
4. Gate passes.

**CONTRACT** (one area):
1. For each read listed, an output DTO and a fixture in `tests/fixtures/screens/`. The fixture covers the states in rp-clone's screenshots for that screen (`docs/screenshots/<area>/`): full, empty, and one warning state where the screen has one.
2. For each write the area's views send, an input DTO with its schema snapshot.
3. Values follow CAP-015 (display-ready, with units) and CAP-018 (statuses carry text and tone).
4. `npm run contracts` regenerates the TS types, and they are committed.
5. `tests/fixtures/schemas/<area>.owners.json` maps every read and write name in the area to the issue number of the API unit that registers it, using the API rows of `docs/plan/build-plan.md` (requirements and sources). Every name has exactly one owner.
6. Nothing is registered. API issues register.
7. Shape sources: the rp-clone view models named in the unit and their published state.

**API** (capabilities for one slice):
1. Every capability that `tests/fixtures/schemas/<area>.owners.json` assigns to this issue is registered from its own class file, with the surfaces in `HostDefinition::SURFACES` (`http`, `job`, `mcp`, `cli`, `agent`), a CLI domain and verb, a CAP-005 description, `affects` (CAP-011), and `reversible` or `destructive` where the spec says (CAP-013, AGT-010).
2. Each `*_show` passes `assertMatchesScreenFixture` against its CONTRACT fixture for a seeded scenario, with the clock fixed.
3. Each requirement ID listed has a test named after it. Rules are unit tests on Actions with I/O mocked. Each capability has one feature test for the seam: 401 signed out, 404 for another user's ID, 422 shape for one bad input.
4. The logic in the rp-clone view models listed moves here, and their tests in `ios/Tests/RPCoreTests/Features/<Folder>/` that cover it become Pest unit tests with the same requirement IDs. The Vue side gets nothing to compute.
5. PARITY passes with these capabilities registered.
6. Gate passes.

**VIEW** (Vue components for one slice):
1. Each SwiftUI view type listed becomes one single-file component of the same name in the folder shown in `docs/spec/19-views.md`. Helpers marked inline there may stay inside their parent.
2. A Vitest component test renders each CONTRACT fixture for the screen: content, empty, loading (skeleton), error, and pending (OFF-008) where the screen shows server-derived numbers. Each write it sends validates against that write's schema snapshot.
3. The script holds display state only (VIEW-002). The `no-business-rules` lint rule passes, and nothing calls `fetch`.
4. Routes go in the feature's `routes.ts`. Each screen opens at `/dev/screens/<name>` with its fixture.
5. The PR includes 390 × 844 screenshots of each screen in light and dark, beside the matching rp-clone screenshot (VIEW-005).
6. Gate passes.

## Units

Key, title (`type: outcome`), blockers, requirements, and what is specific to the unit. Kind templates apply on top.

### Wave 0: skeleton (serial)

| Key | Title | Depends on | Requirements |
|---|---|---|---|
| SKEL_API | feat: Laravel 13 app with one capability working end to end | None | CAP-002, CAP-003, CAP-004, CAP-005, ACC-002 |
| SKEL_WEB | feat: Vue 3 PWA shell that calls the capability rail | SKEL_API | CAP-001, CAP-004 |

**SKEL_API.**
1. A Laravel 13 app on PHP 8.5 sits at the repo root, with MySQL and Redis configured from `.env.example`. Pest is installed with Warp (`WARP_MODE`, `WARP_DB` on), and `phpunit.xml` pins `CAPABILITIES_AI_LLM_DRIVER=fake`.
2. `rawphp/laravel-capabilities` and `rawphp/laravel-capabilities-ai` are required at `0.10.0` from their VCS repos and locked, along with `laravel/mcp` and `laravel/sanctum`.
3. `CapabilitiesHostProvider` is registered last. It loads every `app/Capabilities/*/*Capability.php` and fails boot on a duplicate name. It binds a host authorizer that only checks for a signed-in actor (CAP-020 step 1; RAIL and TOKENS add the rest).
4. Email and password register and sign in work with Sanctum SPA cookie auth on the same domain. `account_show` is registered on all four surfaces and returns the user's name and email. A feature test calls it over `POST /capabilities/account_show` signed in (200) and signed out (401).
5. `scripts/provision.sh` installs Composer packages and copies `.env.example`. `scripts/check.sh` runs the spec-ID check, `pint --test`, `phpstan` and `pest --parallel`, and skips the front-end steps while `package.json` is missing. The spec-ID check fails when a test names an ID that is not in `docs/spec/`.
6. architecture.md gets the conventions table from `docs/plan/build-plan.md`.

**SKEL_WEB.**
1. Vue 3, TypeScript, Vue Router, Tailwind CSS 4, Vite on port 5283, `vite-plugin-pwa` (`injectManifest`) and `idb` are installed. Laravel serves the app shell for every non-API path.
2. `resources/js/app/call.ts` exports `read(name, input)` and `write(name, input)`, which call `POST /capabilities/{name}` with the CSRF cookie. CACHE and OUTBOX extend it later. A Vitest test covers both.
3. Feature routes load from `resources/js/features/*/routes.ts` by glob. A placeholder `features/account/` shows `account_show`.
4. `npm run contracts` generates TS types from `tests/fixtures/schemas/`. Dev routes `/dev/screens/:name` and `/dev/gallery` exist in local and testing only.
5. An ESLint rule `no-business-rules` fails on `fetch(` in `resources/js`, and on arithmetic between two values read from a capability payload inside `features/`. Its README says what the rule allows (CAP-001).
6. `scripts/check.sh` adds `vue-tsc --noEmit`, `vitest run`, `vite build` and, once Playwright tests exist, `playwright test`. `scripts/provision.sh` adds `npm ci`.

### Wave 1: foundations (parallel)

| Key | Title | Depends on | Requirements |
|---|---|---|---|
| SCHEMA | feat: port rp-clone's schema and models | SKEL_API | ACC-005, SET-001 |
| D_CORE | feat: port the shared rule types and default tables | SKEL_API | TBL-001 to TBL-051 |
| RAIL | feat: capability result, idempotency and error conventions | SKEL_API | CAP-010, CAP-011, CAP-012, CAP-014, CAP-015, CAP-017, CAP-018, CAP-019 |
| AUTH | feat: sign in with an emailed code, reset a password, sign out | SKEL_API | ACC-001, ACC-004, ACC-006 |
| CLOCK | feat: time-walk clock for local and testing | SKEL_API, SKEL_WEB | SET-011 |
| TOKENS_CSS | feat: design tokens in light and dark, and the gallery page | SKEL_WEB | PWA-040, PWA-041, PLT-005, SET-002, UI-011, UI-031 |
| SELFHOST | docs: self-hosting guide | SKEL_API | PLT-001 |

- **SCHEMA:** ports every rp-clone migration and model (`server/database/migrations`, `server/app/Models`), except `add_apple_id_to_users_table`, `add_app_account_token_to_users_table`, `create_subscriptions_table` and the `Subscription` model. Every user-owned table keeps `user_id` cascading. Adds `source` and `waist_cm` to weigh-ins (WT-020, WT-021), a `steps` table (date, count, source `manual` or `shortcut`), and ULID primary keys on every table the outbox creates rows in (CAP-019): workout sets, check-ins, weigh-ins, steps, off-plan entries, custom foods, saved meals, recipes. Factories for every model.
- **D_CORE:** DOMAIN template on `Domain/Model` and `Domain/Defaults` (30 + 44 Swift tests). The value types land as PHP readonly classes and enums. Each table in `docs/spec/14-defaults-and-tables.md` is one class in `app/Domain/Defaults`, with a test per row. `Model`'s calls into `MealFit` are left as an interface that D_MEALFIT implements.
- **RAIL:** a base for capabilities. A write returns `invalidates`, `toast` and `undo` (CAP-010). Catalog entries carry `affects` (CAP-011). Errors carry server-written messages (CAP-012). `Idempotency-Key` uses the core `IdempotencyStore` with the database driver: the same key and input replay, a different input gets 422 (CAP-014). A `UsesClientUlid` concern takes a ULID from input and returns 404 if another user owns it (CAP-019). The `Tone` enum covers `good`, `neutral`, `warning`, `info` (CAP-018). The `ReadKey` helper builds canonical keys (CAP-017). Each one is unit tested, with one feature test of the idempotent replay over HTTP.
- **AUTH:** a 6-digit code by email, valid 10 minutes, five tries, then a new code (ACC-001). Password reset by email (ACC-004), and sign out (ACC-006). Rate limits on both mail routes. Host routes, not capabilities (CAP-004).
- **CLOCK:** follows architecture.md, "Walking through time". `UseRequestClock` is first in the capability and API route groups, and `X-BB-Now` is honoured only in `local` and `testing`. `call.ts` sends the header while an offset is set. `features/settings/DeveloperSection.vue` has the picker, "+1 day", "+7 days" and "Clear" (V_SETTINGS2 mounts it). Agent turns record the request clock and apply it in the job. A feature test checks production ignores the header.
- **TOKENS_CSS:** rp-clone's `ios/Sources/RPCore/DesignSystem/ColorToken.swift`, `Macro.swift`, `StatusTone.swift` and `ios/App/DesignSystem/Tokens+SwiftUI.swift` become CSS custom properties in `resources/js/design/tokens.css`, for light and dark. Appearance follows the system, with a stored override (SET-002), and switches without a reload. A Vitest test checks every rp-clone token has a CSS variable, and a contrast test checks text tokens meet WCAG AA (UI-031). It adds the `/dev/gallery` page that loads `design/gallery/*.gallery.vue`.
- **SELFHOST:** `docs/self-hosting.md` covers PHP 8.5, MySQL 8, Redis, the `default` and `agent` queue workers, the scheduler, VAPID keys, mail, the optional Anthropic key and Stripe keys, and `php artisan foods:import` (FOODS). Every env key it names exists in `.env.example`.

### Wave 2: capability rails, design system, first rule layer, contracts (parallel)

| Key | Title | Depends on | Requirements |
|---|---|---|---|
| UNDO | feat: undo for reversible writes | RAIL, SCHEMA | CAP-013, UI-010 |
| TOKENS | feat: one authorizer for every channel, and personal access tokens | RAIL, SCHEMA | CAP-020, CAP-021, CAP-023 |
| AUDIT | feat: audit every write and list it as Activity | RAIL, SCHEMA | CAP-022 |
| AI_SETUP | feat: agent runtime with production defaults and per-user caps | RAIL | AGT-002, AGT-003, AGT-004, AGT-005 |
| PARITY | test: UI and agent capability parity, with an exemption list | AI_SETUP | CAP-030, CAP-031, CAP-032, AGT-001 |
| MCP | feat: product MCP and CLI against personal access tokens | TOKENS, AI_SETUP | AGT-040, AGT-041 |
| PUSH | feat: Web Push subscription and sending | RAIL, SKEL_WEB | NOTIF-010, NOTIF-011 |
| BILLING_API | feat: optional Stripe billing behind one setting | TOKENS | SUB-020 to SUB-024, SUB-001 to SUB-011 |
| CACHE | feat: app shell precache and stale-while-revalidate reads | RAIL, SKEL_WEB | OFF-002, OFF-003, OFF-004, OFF-005 |
| SHELL_NAV | feat: tabs with their own stacks, transitions and edge-swipe back | TOKENS_CSS | NAV-001, NAV-002, NAV-004, PWA-010 to PWA-014, PWA-020 to PWA-025, UI-030, PLT-002 |
| SHELL_PWA | feat: install, splash, icons and quiet updates | TOKENS_CSS | PWA-001 to PWA-004, PWA-035 |
| FOODS | feat: food data import, search, barcode and diet classing | SCHEMA, RAIL | FOOD-001 to FOOD-009 |
| SEED | feat: exercise, template, curated food and recipe seeds | SCHEMA | EX-001, MESO-010 |
| DS_CHOICE | feat: ChoiceList component | TOKENS_CSS | UI-006 |
| DS_HELP | feat: HelpButton component | TOKENS_CSS | UI-009, LRN-003 |
| DS_CARD | feat: ItemCard component | TOKENS_CSS | UI-002 |
| DS_BAR | feat: ProgressBar component | TOKENS_CSS | UI-003 |
| DS_SHEET | feat: BottomSheet component | TOKENS_CSS | UI-005, UI-022, PWA-023 |
| DS_PILL | feat: StatusPill component | TOKENS_CSS | UI-004 |
| DS_STEPS | feat: StepFlow component | TOKENS_CSS | UI-007 |
| DS_TOAST | feat: Toast with Undo | TOKENS_CSS | UI-010 |
| DS_ROW | feat: ValueRow component | TOKENS_CSS | UI-008, UI-020 |
| DS_WEEK | feat: WeekStrip component | TOKENS_CSS | UI-001 |
| DS_STATES | feat: pending mark, offline notice and skeletons | TOKENS_CSS | OFF-008, OFF-009, OFF-031, PWA-024 |
| D_SAFETY | feat: port Safety rules | D_CORE | SAFE |
| D_SHOPPING | feat: port Shopping rules | D_CORE | SHOP |
| D_MACROS | feat: port Macros rules | D_CORE | NUT-010 to NUT-018 |
| D_WEIGHT | feat: port Weight rules | D_CORE | WT-010 to WT-012 |
| D_PROGRESSION | feat: port Progression rules | D_CORE | PROG |
| D_MEALFIT | feat: port MealFit rules | D_CORE | MEAL-001 to MEAL-011 |
| D_PHASES | feat: port Phases rules | D_CORE | PH |
| D_PLACEMENT | feat: port Placement rules | D_CORE | SCH-040 to SCH-046 |
| D_MESOCYCLE | feat: port Mesocycle rules | D_CORE | MESO |
| D_PLAN | feat: port Plan rules | D_CORE | PLAN |
| C_ONB | feat: onboarding screen contracts | RAIL, D_CORE | ONB, SAFE-001 |
| C_TODAY | feat: Today screen contracts | RAIL, D_CORE | SCH, DAY |
| C_TRAIN | feat: Train, plan, mesocycle, exercise and workout contracts | RAIL, D_CORE | PLAN, PH, MESO, EX, WO, FB |
| C_NUTRITION | feat: Nutrition, meal, food, recipe and shopping contracts | RAIL, D_CORE | MEAL, FOOD, RCP, SHOP |
| C_PROGRESS | feat: Progress, review and weigh-in contracts | RAIL, D_CORE | REV, WT, ANA |
| C_SETTINGS | feat: Settings, More, account, tokens, bridge, coach, learn and billing contracts | RAIL, D_CORE | SET, LRN, INT, CAP-021, AGT-020, SUB-007 |

- **UNDO:** `undo_apply` reverts a reversible write and everything it changed automatically. A write stores its inverse with the undo token. Undo is refused, with a message, when a later write touched the same rows. Tokens expire after 24 hours. A test on a fake reversible capability covers apply, refuse and expiry.
- **TOKENS:** the authorizer runs CAP-020's five checks in order, and the billing and agent steps call interfaces BILLING_API and COACH_CTX fill in (no-op by default). It adds `token_create`, `token_list`, `token_revoke` and the scopes "Everything", "Read only", "Health bridge" and custom (CAP-021, CAP-023). It has feature tests for cross-user 404 and an out-of-scope 403.
- **AUDIT:** every invoke records actor, channel (`ui`, `agent`, `mcp`, `cli`, `bridge`, derived on the server), time, input and result, and `activity_show` returns the last 100 writes. A daily pruning keeps 90 days.
- **AI_SETUP:** the published config uses the values in 17-agent.md, "Setup defaults". The `agent` queue is defined, `capabilities-ai:reap-stale-turns` is scheduled once, and `capabilities:integration-health` passes. The tool catalog comes from the registry minus the CAP-031 exclusion list, with schemas normalised for Anthropic. Tools invoke with `caller=agent` and the conversation user as actor, with a test that a tool call from a turn reaches the authorizer as that user (AGT-004). AGT-002 on/off is a capability `agent_status_show`. AGT-005 caps come from config, with tests for near-cap and at-cap. Chat routes stay the package's.
- **PARITY:** the CAP-030 test reads every capability name passed to `read(` or `write(` in `resources/js`. Each must be registered and in the agent profile, or else not registered yet and present in a schema snapshot under `tests/fixtures/schemas/` (a contract waiting for its API issue). A name in neither fails. A registered name missing from the agent profile fails unless it is on the CAP-031 exclusion list in `app/Agent/Exclusions.php`, which holds a reason per entry. It also adds the CAP-032 tests: a schema snapshot and the declared surfaces for every registered capability.
- **MCP:** `/mcp/bodybuilder` serves the same profile as the agent, behind personal access tokens. `capabilities:mcp-status` reports it. A test lists tools over MCP with a token.
- **PUSH:** VAPID keys from env, `push_subscribe` and `push_unsubscribe` capabilities, a sender job, and a Vue composable that asks permission only after the user opts in (NOTIF-010). It shows the home-screen hint on iOS. Notification clicks open the URL in the payload (NOTIF-011).
- **BILLING_API:** everything is off unless `BILLING_ENABLED=true` (SUB-020, with a test that no billing capability registers when off). Stripe Checkout and customer portal session capabilities, a host webhook route that verifies signatures, a trial after onboarding (SUB-003), and the authorizer's billing step: lapsed users are read-only except for the SUB-022 exemptions. Agent included on the plan (SUB-024).
- **CACHE:** the service worker precaches the shell. `read()` serves IndexedDB first and revalidates in the background, keyed by `ReadKey`. `sync_manifest` returns keys from every read capability that implements `PrefetchesKeys` (none yet; API issues add theirs). Persistent storage is requested after onboarding, and eviction follows OFF-005. Vitest tests use `fake-indexeddb`.
- **SHELL_NAV:** `AppShell`, `TabBar`, `TabStack`, a sheet host and a toast host. Five tabs, each with its own stack and scroll memory (PWA-020). Slide push and pop with reduced-motion cross-fade (PWA-021). Edge-swipe back that suppresses the browser's own (PWA-022). Safe areas, no page bounce, no callouts, 16 px inputs and keypad modes (PWA-010 to PWA-014). Browser and system back follow the stack (PWA-025), and text scales to 200% (UI-030). It has a slot for the ActiveWorkoutBar (NAV-004, filled by V_WO1) and one for the Coach button (filled by V_COACH).
- **SHELL_PWA:** manifest, maskable icons, iOS meta tags and launch images (PWA-001, PWA-002). The install prompt after onboarding, with a 30-day snooze (PWA-003). A cold start from cache within 1 second, checked by a Playwright trace on a throttled profile (PWA-004). An update banner that never reloads mid-workout or with a sheet open (PWA-035). The icon is a simple generated mark; record it in `## Notes` as a placeholder for Tom to replace.
- **FOODS:** ports rp-clone's `server/app/Foods/*`, `server/app/Console/Commands/ImportFoods.php` and their tests. Open Food Facts attribution appears in the food detail and in Settings → About (ODbL). Imports run at install, and no dataset is committed.
- **SEED:** ports rp-clone's `ExerciseSeeder`, `TemplateSeeder`, `CuratedFoodSeeder`, `RecipeSeeder` and `database/seeders/data/`. Exercise `video_url`s are kept as they are.
- **DS_\*:** each ports its rp-clone `ios/App/DesignSystem/<Name>.swift`, plus its gallery file with the VIEW-004 states and a Vitest component test. DS_SHEET: Cancel, title and Save layout, drag to close, half and full heights, and a no-dismiss mode for UI-022. DS_ROW: 48 px targets in gym mode (UI-020). DS_TOAST: Undo calls `write('undo_apply', {token})`.
- **D_\*:** DOMAIN template. Swift test counts at the pin: Safety 35, Shopping 21, Macros 67, Weight 11, Progression 2, MealFit 40, Phases 34, Placement 36, Mesocycle 22, Plan 145. D_PLAN may run long; split it by Swift file and file the rest as a sibling if the first half fills the run. D_MEALFIT implements D_CORE's `MealFit` interface.
- **C_\*:** CONTRACT template. Reads and writes per area, from `docs/spec/15-capabilities.md`'s inventory:
  - C_ONB: `onboarding_show`, writes `onboarding_answer` and `onboarding_complete`. Shapes from `Features/Onboarding/*`.
  - C_TODAY: `today_show`, `week_show`, and every write in the "schedule and today" row, plus `meal_check_in` as Today sends it. Shapes from `Features/Today/*` and `Features/Copy/*`.
  - C_TRAIN: `train_show`, `plan_show`, `phase_show`, `mesocycle_show`, `templates_show`, `exercises_show`, `exercise_show`, `workout_show`, and their rows' writes. `workout_show` includes the CAP-016 rep tables and the OFF-020 fields. Shapes from `Features/Plan`, `Mesocycles`, `Exercises`, `Workout`.
  - C_NUTRITION: `nutrition_show`, `meal_show`, `food_search`, `food_barcode_lookup`, `saved_meals_show`, `recipes_show`, `recipe_show`, `shopping_show`, and their rows' writes, plus the scan drafts. Shapes from `Features/Meal`, `Pantry`, `CustomFoods`, `Scan`, `SavedMeals`, `Recipes`, `Shopping`.
  - C_PROGRESS: `progress_show`, `exercise_history_show`, `review_show`, `weigh_ins_show`, and their rows' writes. Shapes from `Features/Progress`, `Review`, `WeighIns`.
  - C_SETTINGS: `settings_show`, `account_show`, `activity_show`, `token_list`, `agent_status_show`, `learn_show`, `article_show`, the health bridge status, billing status, and their writes. Shapes from `Features/Settings`, `Account`, `Learn`, `Subscription`, `Notifications`.

### Wave 3 and on: rules, offline, agent, screens

Rules:

| Key | Title | Depends on | Requirements |
|---|---|---|---|
| D_MEALSPLIT | feat: port MealSplit rules | D_MACROS | NUT-030 to NUT-037 |
| D_PROGRESS | feat: port Progress rules | D_PHASES | ANA |
| D_DAYBALANCE | feat: port DayBalance rules | D_MACROS | DAY |
| D_ENERGY | feat: port Energy rules | D_MACROS | NUT-001 to NUT-007 |
| D_RECIPE | feat: port Recipe rules | D_MEALFIT | RCP |
| D_RETARGET | feat: port Retarget rules | D_ENERGY, D_MEALSPLIT, D_MESOCYCLE, D_PLACEMENT | SCH-050 to SCH-058 |
| D_WEEKBUILDER | feat: port WeekBuilder rules | D_ENERGY, D_MACROS, D_MEALSPLIT, D_PLACEMENT, D_PLAN | NUT-020 to NUT-024, PLAN-020 to PLAN-030 |
| D_REVIEW | feat: port Review rules | D_PROGRESS, D_WEEKBUILDER | REV-020 to REV-046 |

Swift test counts: MealSplit 28, Progress 47, DayBalance 16, Energy 31, Recipe 10, Retarget 58, WeekBuilder 55, Review 66.

Offline, AI food, agent:

| Key | Title | Depends on | Requirements |
|---|---|---|---|
| OUTBOX | feat: write outbox with ordered replay and a couldn't-sync tray | CACHE, DS_STATES | OFF-006 to OFF-012, OFF-051, ACC-003 |
| AI_FOOD | feat: AI label, photo, description and recipe calls | AI_SETUP, FOODS | FOOD-042 to FOOD-044, RCP-010 |
| COACH_CTX | feat: Coach context, confirm-before-destructive and behaviour rules | AI_SETUP, TOKENS, A_TODAY1 | AGT-010 to AGT-015, AGT-024 |
| V_COACH | feat: Coach button, streaming chat and change cards | AI_SETUP, DS_SHEET, SHELL_NAV | AGT-013, AGT-020 to AGT-023 |
| ONB_CHAT | feat: onboarding by chat | COACH_CTX, V_COACH, A_ONB2, V_ONB2 | AGT-030 to AGT-033 |

- **OUTBOX:** `write()` stores the entry first and replays in order, as one path online or offline. It covers OFF-006 to OFF-012, the `affects`-driven pending state (OFF-008), the row overlay API views use for their own pending input (OFF-009), `OutboxView.vue`, and the sync line component (ACC-003). OFF-051 replays one outbox twice against a feature-test server.
- **AI_FOOD:** ports rp-clone's `server/app/Ai/*` onto the package's `LlmClient` (not a second Anthropic client). Results are drafts, and nothing is saved until the user confirms. With no key, these capabilities deny with a clear message.
- **V_SIGNIN:** `features/account/SignInView.vue` ports rp-clone's `SignInView` without Sign in with Apple: email and password, or "Email me a code" then a 6-digit code field with resend after 30 seconds, plus Forgot password. It calls AUTH's host routes, not capabilities, so in place of VIEW criterion 2 its Vitest test covers signed-out, wrong password, wrong code, expired code and rate-limited states with the routes mocked.
- **V_COACH:** `app/CoachButton.vue` in the shell's Coach slot, shown only when `agent_status_show` says on (AGT-002). `features/coach/CoachChat.vue` is a full-height sheet: it streams the package's turn events over SSE, shows each tool call as a line while it runs, and cancels a turn (AGT-021). It attaches a photo from the camera or library through a host upload route (AGT-022). Offline it shows cached history with the input disabled (AGT-023). `features/coach/ChangeCard.vue` renders each agent write with a link to its screen and Undo (AGT-013). Vitest tests use a recorded event stream. There is no rp-clone source, so the PR includes 390 × 844 screenshots in light and dark.
- **ONB_CHAT:** the onboarding doors (AGT-030) lead to the chat. The agent records answers with `onboarding_answer` (AGT-031). Screening opens `ScreeningStep` as a sheet inside the chat, and its answers are sent from the sheet, not the model. The review summary renders `ReviewStep`'s content as a card, and the user confirms it before `onboarding_complete` (AGT-032). Leaving the chat resumes the stepper with every answer kept (AGT-033). Tested with the fake LLM driver scripted through a full onboarding.
- **BRIDGE:** API and view in one issue. The health bridge read and the `weigh_in_log` and `steps_log` scope come from TOKENS. Settings → Health bridge creates the scoped token, shows it once, and links the shared Shortcut. Its import URL is a config value, with the Shortcut's steps written out on the page so a user can build it by hand. It shows the last post and what it carried (INT-004). A feature test posts a weigh-in and steps with a bridge token and gets 403 on any other capability.
- **COACH_CTX:** ports the behaviour rules from mesoprep's `server/resources/coach/AGENTS.md` that fit 17-agent.md, and drops its MesoPrep-only commands. The context prefix is cached and the suffix is per turn (AGT-024). The authorizer's agent step refuses a `destructive` capability without a confirmation marker in the turn (AGT-010).

Screens. Each API row follows the API template and each V_ row the VIEW template. "Sources" are rp-clone `ios/Sources/RPCore/Features/<Folder>/` files for API rows, and the view types in `docs/spec/19-views.md` for V_ rows.

| Key | Title | Depends on | Requirements | Sources |
|---|---|---|---|---|
| A_ONB1 | feat: onboarding answers, goal, body fat and screening | C_ONB, D_SAFETY, D_PHASES, TOKENS | ONB-001 to ONB-012, SAFE | Onboarding: OnboardingModel, OnboardingChoices, OnboardingDraft, OnboardingDraftStore, OnboardingIntro, +Health |
| A_ONB2 | feat: onboarding lifestyle, training, rate and plan creation | C_ONB, D_PLAN, D_MESOCYCLE, D_WEEKBUILDER, TOKENS | ONB-013 to ONB-021, ONB-030 to ONB-033 | Onboarding: +Lifestyle, +Training, +RateLength, +Review, +Plan, +Start, OnboardingService, SetupGate |
| A_TODAY1 | feat: Today, week strip and the day timeline | C_TODAY, D_WEEKBUILDER, D_PLACEMENT, D_DAYBALANCE, TOKENS | SCH-001 to SCH-023, MESO-060 | Today: TodayModel, TodayItems, +Timeline, +Meals, +ScheduleMeals, +MealScreen, +Review, +NextMesocycle, TodayTour |
| A_TODAY2 | feat: move, time and add workouts; edit the schedule | C_TODAY, D_WEEKBUILDER, D_RETARGET, UNDO, TOKENS | SCH-030 to SCH-039 | Today: ScheduleSheetModels, ScheduleSnapshot+Pauses, +AddWorkout, +EditSchedule, +WeekdayChange |
| A_TODAY3 | feat: re-target, adjust, redistribute and off-plan | C_TODAY, D_RETARGET, D_DAYBALANCE, D_MEALSPLIT, UNDO, TOKENS | SCH-050 to SCH-058, DAY-001 to DAY-021 | Today: +Retarget, +Adjust, +Redistribute, +OffPlan, +PlannedFoods |
| A_TODAY4 | feat: away weeks, missed weeks and copying days | C_TODAY, D_WEEKBUILDER, D_MESOCYCLE, UNDO, TOKENS | SCH-060 to SCH-064, MEAL-060 to MEAL-071 (copy) | Today: AwayWeekModel, +Away, +MissedWeeks, +Copy, +CopyWeek; Copy: CopyModel, CopyPlanner, CopyTypes |
| A_MEAL | feat: meal screen, meal builder and check-ins | C_NUTRITION, D_MEALFIT, D_MEALSPLIT, D_DAYBALANCE, FOODS, UNDO, TOKENS | MEAL-001 to MEAL-047, FOOD-080, FOOD-081, OFF-030 | Meal: all |
| A_PANTRY | feat: food search, favourites, recents and diet filters | C_NUTRITION, FOODS, TOKENS | FOOD-020 to FOOD-029 | Pantry: all |
| A_CUSTOMFOOD | feat: custom foods | C_NUTRITION, FOODS, D_MACROS, TOKENS | FOOD-060 to FOOD-062 | CustomFoods: all |
| A_SCAN | feat: barcode, label, photo and description scans | C_NUTRITION, AI_FOOD, TOKENS | FOOD-040 to FOOD-046 | Scan: all |
| A_SAVED | feat: saved meals | C_NUTRITION, TOKENS | MEAL-060 to MEAL-071 (saved) | SavedMeals: all |
| A_RECIPE | feat: recipes, library, generate and import | C_NUTRITION, D_RECIPE, AI_FOOD, TOKENS | RCP | Recipes: all |
| A_SHOP | feat: shopping list | C_NUTRITION, D_SHOPPING, TOKENS | SHOP, OFF-030 | Shopping: all |
| A_PLAN1 | feat: plan timeline, phase detail and diet breaks | C_TRAIN, D_PLAN, D_PHASES, UNDO, TOKENS | PLAN-001 to PLAN-030, PH-001 to PH-012 | Plan: PlanTimeline, PlanTimelineModel, +DietBreak, DietBreakPrompt, PhaseDetail, PhaseDetailModel, PlanContext, PlanDataSource |
| A_PLAN2 | feat: add a phase, prebuilt plans and phase transitions | C_TRAIN, D_PLAN, D_PHASES, D_WEEKBUILDER, TOKENS | PH-030 to PH-046 | Plan: AddPhaseModel, PrebuiltPlans, PhaseTransitionModel |
| A_MESO1 | feat: Train tab, mesocycle view and editing, next mesocycle | C_TRAIN, D_MESOCYCLE, D_PROGRESSION, UNDO, TOKENS | MESO-001 to MESO-007, MESO-040 to MESO-065 | Plan: TrainModel; Mesocycles: MesocycleListModel, MesocycleViewModel (+Days, +End), MesocycleParts, MesocycleRecordMapping, MesocycleViewTypes, ProgressionRatesModel |
| A_MESO2 | feat: Meso Builder, templates and muscle priorities | C_TRAIN, D_MESOCYCLE, D_PLAN, SEED, TOKENS | MESO-010 to MESO-034 | Mesocycles: MesocycleBuildModel, TemplateLibraryModel |
| A_EX | feat: exercise library, detail, custom exercises, notes and load steps | C_TRAIN, SEED, TOKENS | EX | Exercises: all |
| A_WO1 | feat: workout screen and logging sets | C_TRAIN, D_PROGRESSION, D_MESOCYCLE, D_PLACEMENT, TOKENS | WO-001 to WO-025, PROG-020 to PROG-027, OFF-020 to OFF-022 | Workout: WorkoutModel, +Edit (sets), WorkoutTypes, WorkoutUnits, ActiveWorkout |
| A_WO2 | feat: feedback, recovery, finishing and progression | C_TRAIN, D_PROGRESSION, D_RETARGET, UNDO, TOKENS | FB, PROG-001 to PROG-019, PROG-030 to PROG-055, WO-010, OFF-023 | Workout: FeedbackSheetModel, +Feedback, +Recovery, ProgressionApply |
| A_WO3 | feat: swap, add, reorder and relabel exercises; look back | C_TRAIN, D_MESOCYCLE, A_EX, UNDO, TOKENS | WO-030 to WO-052 | Workout: +Edit (session actions), WorkoutHelp |
| A_REV1 | feat: weekly review analysis and recommendation | C_PROGRESS, D_REVIEW, TOKENS | REV-001 to REV-005, REV-020 to REV-050 | Review: ReviewAnalysis, ReviewMessages, ReviewDataSource, ReviewPlanContext, WeeklyReviewModel |
| A_REV2 | feat: weekly review calorie choice, preview and save | C_PROGRESS, D_REVIEW, D_WEEKBUILDER, UNDO, TOKENS | REV-010 to REV-013 | Review: +Calories, +Preview, +Save |
| A_WT | feat: weigh-ins, schedule, steps and waist | C_PROGRESS, D_WEIGHT, TOKENS | WT-001 to WT-021, OFF-030 | WeighIns: all |
| A_PROGRESS | feat: Progress tab, history and exercise history | C_PROGRESS, D_PROGRESS, D_WEIGHT, TOKENS | ANA | Progress: all |
| A_SET1 | feat: settings, profile, units and schedule defaults | C_SETTINGS, D_WEEKBUILDER, TOKENS | SET-001 to SET-009, SET-020 | Settings: SettingsModel, ProfileStore, HeightDisplay, AppearanceSettings, MoreMenu |
| A_SET2 | feat: eligibility recheck, phase switch, plan rebuild and account deletion | C_SETTINGS, D_SAFETY, D_PLAN, D_WEEKBUILDER, TOKENS | SAFE-017, SAFE-018, ACC-005 | Settings: EligibilityRecheckModel, PhaseSwitch, PlanRebuild, TrainingWeekdayChange, AccountActionsModel |
| A_NOTIF | feat: reminders sent by the server | C_SETTINGS, PUSH, D_WEEKBUILDER, TOKENS | NOTIF-001 to NOTIF-007 | Notifications: all |
| A_LEARN | feat: Learn articles and help links | C_SETTINGS, TOKENS | LRN-001 to LRN-006 | Learn: all (articles copied as they are; CONTENT_LEARN rewrites them) |
| BRIDGE | feat: Health bridge through an iOS Shortcut | C_SETTINGS, TOKENS, A_WT, DS_ROW | INT-001 to INT-005 | Health: HealthSettings (copy only) |
| V_SIGNIN | feat: sign-in screen | AUTH, SHELL_NAV, DS_ROW | ACC-001 | Account |
| V_ONB1 | feat: onboarding intro, goal, about you, body fat and screening screens | C_ONB, DS_STEPS, DS_CHOICE, DS_HELP, SHELL_NAV | ONB-001 to ONB-012 | Onboarding: OnboardingView, IntroView, AboutYouStep, BodyFatStep, ScreeningStep, OnboardingParts |
| V_ONB2 | feat: onboarding lifestyle, training, rate and review screens | V_ONB1, C_ONB | ONB-013 to ONB-021, ONB-030, AGT-030 | Onboarding: LifestyleSteps, TrainingSteps, RateLengthStep, ReviewStep, SetupGateView |
| V_TODAY1 | feat: Today screen and its cards | C_TODAY, DS_CARD, DS_BAR, DS_WEEK, DS_PILL, DS_STATES, OUTBOX, SHELL_NAV | SCH-001 to SCH-023, ONB-030 | Today: TodayView, TodayCards, TodayCheckIn, NextMesocycleCard, TodayTourOverlay |
| V_TODAY2 | feat: Today schedule sheets | C_TODAY, DS_SHEET, DS_CHOICE, V_ONB1 | SCH-030 to SCH-039 | Today: TodaySheets (calendar, time, add meal, move day), AddWorkoutSheet, ScheduleSheets, WeekdayChangeSheet |
| V_TODAY3 | feat: adjust, redistribute and off-plan sheets | C_TODAY, DS_SHEET, DS_ROW, DS_TOAST | DAY | Today: AdjustSheet, RedistributeSheet, OffPlanViews, TodayOptimizeSheet |
| V_TODAY4 | feat: away week, missed week and copy sheets | C_TODAY, DS_SHEET, DS_CHOICE | SCH-060 to SCH-064 | Today: AwayWeekSheet, TodayMissedWeeksSheet, TodayMissedDaySheet; Copy: CopySheet |
| V_NUTRITION | feat: Nutrition tab and meal screen | C_NUTRITION, DS_CARD, DS_BAR, DS_ROW, DS_SHEET, DS_STATES, OUTBOX, SHELL_NAV | MEAL, UI-021 | Nutrition: NutritionView; Meal: MealView, MealParts |
| V_PANTRY | feat: pantry and food search | C_NUTRITION, DS_ROW, SHELL_NAV | FOOD-020 to FOOD-029 | Pantry: all |
| V_FOODS | feat: custom foods and saved meals screens | C_NUTRITION, DS_SHEET, DS_ROW | FOOD-060 to FOOD-062, MEAL-060 to MEAL-071 | CustomFoods: all; SavedMeals: all |
| V_SCAN | feat: scan screen with live barcode camera | C_NUTRITION, DS_SHEET, SHELL_NAV | FOOD-040 to FOOD-046, PWA-031, PWA-032, PLT-003 | Scan: all |
| V_RECIPES | feat: recipe screens | C_NUTRITION, DS_STEPS, SHELL_NAV | RCP | Recipes: all |
| V_SHOP | feat: shopping list screen | C_NUTRITION, DS_ROW, OUTBOX | SHOP | Shopping: all |
| V_PLAN1 | feat: plan, timeline and phase detail screens | C_TRAIN, DS_CARD, DS_SHEET, SHELL_NAV | PLAN, PH-001 to PH-012 | Plan: PlanView, PlanTimelineView, PhaseDetailView |
| V_PLAN2 | feat: add phase, prebuilt plans and phase transition screens | C_TRAIN, DS_STEPS, DS_CHOICE | PH-030 to PH-046 | Plan: AddPhaseView, PrebuiltPlansView, PhaseTransitionView |
| V_MESO | feat: Train tab and mesocycle screens | C_TRAIN, DS_CARD, DS_BAR, SHELL_NAV | MESO-001 to MESO-007, MESO-030 to MESO-065 | Train: TrainView; Mesocycles: MesocyclesView, MesocycleView, CurrentMesocycleView, ProgressionRatesView, PriorityViews |
| V_MESO_BUILD | feat: Meso Builder and template library screens | C_TRAIN, DS_STEPS, DS_CHOICE | MESO-010 to MESO-023 | Mesocycles: MesocycleBuildView, BuildSteps, TemplateLibraryView |
| V_EX | feat: exercise library and exercise screens | C_TRAIN, DS_SHEET, SHELL_NAV | EX | Exercises: all |
| V_WO1 | feat: workout screen for the gym | C_TRAIN, DS_ROW, DS_BAR, DS_STATES, OUTBOX, SHELL_NAV | WO-001 to WO-025, UI-020, NAV-004, PWA-030, PWA-033, CAP-016 | Workout: WorkoutView, WorkoutExerciseCard, WorkoutSetRow, ActiveWorkoutBar |
| V_WO2 | feat: feedback and workout sheets | V_WO1, DS_SHEET, DS_CHOICE | FB, WO-030 to WO-052, UI-022 | Workout: FeedbackSheet, WorkoutSheets |
| V_REVIEW | feat: weekly review flow | C_PROGRESS, DS_STEPS, DS_CHOICE, DS_PILL | REV | Review: all |
| V_WT | feat: weigh-ins, schedule and steps screens | C_PROGRESS, DS_SHEET, DS_ROW, OUTBOX | WT | WeighIns: all |
| V_PROGRESS | feat: Progress tab, charts and exercise history | C_PROGRESS, DS_CARD, SHELL_NAV | ANA, PWA-034 | Progress: all |
| V_SETTINGS1 | feat: settings, profile, schedule and notification screens | C_SETTINGS, PUSH, V_ONB1, SHELL_NAV | SET-001 to SET-009, NOTIF-007, NOTIF-010 | Settings: SettingsView, ProfileEditView, ScheduleEditView; NotificationSettingsView (new) |
| V_SETTINGS2 | feat: eligibility, delete account, tokens, coach and developer screens | C_SETTINGS, CLOCK, SHELL_NAV | SAFE-017, ACC-005, CAP-021, AGT-020, SET-010, SET-011 | Settings: EligibilityRecheckView, DeleteAccountView; TokensView, CoachSettingsView (new) |
| V_MORE | feat: More menu and Activity | C_SETTINGS, AUDIT, OUTBOX, SHELL_NAV | SET-020, CAP-022, ACC-003 | More: MoreView; ActivityView (new) |
| V_LEARN | feat: Learn screens | C_SETTINGS, DS_HELP | LRN | Learn: all |
| V_BILLING | feat: paywall and lapsed banner, shown only with billing on | BILLING_API, C_SETTINGS, DS_SHEET | SUB-003, SUB-004, SUB-010 | Subscription: PaywallView, LapsedBannerView |

### Last wave: journeys and proof

| Key | Title | Depends on | Requirements |
|---|---|---|---|
| E2E_ONB | test: onboarding to the first finished workout | A_ONB2, V_ONB2, A_TODAY1, V_TODAY1, A_WO1, A_WO2, V_WO1, V_WO2 | ONB, WO |
| E2E_OFFLINE | test: a whole workout offline | A_WO1, A_WO2, A_TODAY3, V_WO1, V_WO2, OUTBOX, CACHE | OFF-001, OFF-050 |
| E2E_CHAT | test: onboarding by chat against the fake model | ONB_CHAT | AGT-030 to AGT-033 |
| EVALS | test: agent eval set | COACH_CTX, ONB_CHAT, A_TODAY2, A_TODAY3, A_WO3, A_MEAL, UNDO | AGT-050 |
| AUDIT_UI | test: Lighthouse and installability on Today, Workout and Meal | V_TODAY1, V_WO1, V_NUTRITION, SHELL_PWA | PWA-051 |

Playwright runs on a 390 × 844 viewport, with the seeders and the clock fixed (CLOCK), in `tests/e2e/`.

- **E2E_ONB:** a new account completes stepper onboarding, sees Today, starts today's workout, logs every set, answers the feedback and finishes. The test asserts the sets saved and Today's meals re-targeted.
- **E2E_OFFLINE:** OFF-050 step by step, with the browser context set offline after the workout starts and back online at the end. It asserts each set is stored exactly once, with the offline `occurred_at` times.
- **E2E_CHAT:** chat onboarding against the fake LLM driver, scripted through every step, ending at Today with the same plan the stepper would build from the same answers.
- **EVALS:** `tests/Agent/` holds at least 30 scripted requests, each with the tool calls and arguments it must produce, including every case AGT-050 lists. They run against the fake driver in the gate. `php artisan agent:eval --live` runs them against the real model and writes the pass rate to `tests/Agent/results/<date>.json`; it is not part of the gate.
- **AUDIT_UI:** Lighthouse runs in CI mode on Today, Workout and Meal against seeded data, failing on any accessibility or best-practices failure. A Playwright check confirms the manifest has no installability errors (PWA-051).

### Parked

| Key | Title | Why parked |
|---|---|---|
| CONTENT_LEARN | docs: rewrite the Learn articles in Bodybuilder's own words | Postponed. Do not mark factory:ready. rp-clone's 37 articles paraphrase RP material. Un-park before the repo is promoted publicly. |

- **CONTENT_LEARN:** depends on A_LEARN. Each article under `resources/learn/` is rewritten in plain words of Bodybuilder's own, covering the same topic list (LRN-001, LRN-002), with no sentence carried over from rp-clone's copy, and no RP product names except where the spec's methods need the source named. The PR lists each article with a one-line summary.

Not issues: PWA-050 (walking each milestone on a real iPhone and Android phone) is Tom's checklist, not a build. The licence decision in architecture.md, "Open questions", is Tom's call.

## Coverage

Every requirement ID is named by at least one unit above, as a single ID, a range, or a whole prefix (for example `SAFE`, which means every SAFE ID). The filing script expands prefixes and ranges against `docs/spec/` and fails if any active ID is unnamed. IDs marked Dropped or Deferred are skipped. VIEW-001 to VIEW-005 are covered by the VIEW template in every V_ unit. PWA-050 is not an issue (see Parked).

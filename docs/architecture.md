# Architecture

Decisions every issue follows. The product spec is `docs/spec/`; requirement IDs (for example CAP-001) refer to it.

## One sentence

A Laravel 13 app owns every rule and exposes every action as a capability. A Vue 3 PWA calls those capabilities and only displays. The optional agent calls the same capabilities in-process.

```text
 Vue PWA ──► outbox / cache ──► POST /capabilities/{name} ─┐
 CLI / Shortcut ──────────────► POST /capabilities/{name} ─┤  (token)
 MCP client ─────► POST /mcp/bodybuilder ► McpToolAdapter ─┤  (token, caller=mcp)
 In-app agent (TurnRunner) ──► CapabilityBus::invoke ──────┤  (caller=agent, actor=user)
                                                           ▼
                        CapabilityRegistry → authorize (CAP-020) → run() → audit
                                                           │
                                                 app/Domain rules + MySQL
```

## Platforms

- PWA, phone first: iOS 17.4+ Safari installed to the home screen, Android Chrome 120+. Desktop browsers and iPad get the left-rail layout at 768 px and wider (PLT-001, PLT-002, NAV-003).
- No native shell in v1.
- The same code runs self-hosted (billing off) and as the paid hosted version (billing on). Hosted-only behaviour is configuration (SUB-023).

## Wide screens

- One breakpoint: 768 px. Script uses `WIDE_MIN_PX` / `WIDE_QUERY` from `resources/js/app/layout.ts`, Tailwind uses `md:`, CSS uses `@media (min-width: 768px)`.
- At that width the shell (`resources/js/app/shell.css`) turns the tab bar into a left rail and gives every screen a centred 40rem column. A screen that needs more room (two columns, a wider table) widens its own root with `md:` classes and says why in a comment.
- A new or changed screen must not use `position: fixed`, `100dvh`/`100vh`/`min-h-dvh`/`h-screen`, or a fixed `max-w-*` cap on its root for page layout. A bar pinned to the bottom of a screen uses `sticky` inside the screen, not `fixed` to the viewport.
- New or changed sheets, menus and overlays must go through the shared components in `resources/js/design/` and the sheet host, not their own `fixed` layers.
- Existing screens do not all follow these rules yet. The per-area sweep split from #246 fixes them. Until it lands, these are the known exceptions: `design/BottomSheet.vue`, `design/HelpButton.vue` and `app/PwaOverlay.vue` (`fixed`, `dvh`), `features/today/sheets/TodayTourOverlay.vue` and `features/onboarding/StartingPlanView.vue` (`fixed inset-0`), `features/onboarding/IntroView.vue`, `features/learn/LearnIndexView.vue` and `features/learn/ArticleView.vue` (`min-h-dvh`), `features/onboarding/SetupDoors.vue` (`min-h-[70dvh]`), and the root `max-w-*` caps on `features/onboarding/IntroView.vue`, `features/onboarding/OnboardingView.vue`, `features/account/SignInView.vue` and `features/review/WeeklyReviewView.vue`. When you change one of these screens, bring it in line with this section. `features/progress/ProgressScreen.vue` has a `fixed` element on purpose: it draws the share chart off screen (`-left-[9999px]`), so it is not page layout and is not an exception.
- A new or changed screen is checked at 390 × 844 and at 1440 × 900 before it ships, and its issue says what it shows on a wide screen.
- Adding a second breakpoint means editing this section first.

## Layout

One Laravel app at the repo root. There is no `server/` folder and no separate front-end repo.

```text
app/
  Domain/<Module>/        rules: plain PHP, no Eloquent, no facades; unit tested
  Actions/<Module>/       one class per write: loads models, calls Domain, saves, returns the result
  Capabilities/<Module>/  one class per capability, and its DTOs under Data/
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

## Conventions

Parallel issues rely on these names.

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
| Tab roots | a route at `/today`, `/train`, `/nutrition`, `/progress` or `/more` takes over that tab's placeholder (`app/tabs.ts`), which links the tab's screens without params until then. Route `meta.tab` picks the tab a link straight to a screen opens in; a push joins the active tab (NAV-002). `meta.shell: false` renders outside the tabs. `meta.edgeZone` narrows the swipe-back zone (16 on the workout screen) |
| Shell slots | `app/ActiveWorkoutBar.vue` and `app/CoachButton.vue` appear in the shell by existing. Sheets and toasts teleport to `SHEET_HOST` and `TOAST_HOST` (`app/hosts.ts`). Screens take route params as props, and go back with `router.back()` |
| Dev screens | `/dev/screens/<capability_name>` renders the screen from its fixture, `/dev/screens/<capability_name>.<state>` from `<capability_name>.<state>.json` (local and testing only). When several routes read the same capability, add `?view=<route name>` to pick one; without it the first matching route wins. Any other single-valued query key is passed to the screen as a string prop (`?pending=` turns a Boolean prop on), for example `/dev/screens/food_search?mealId=<id>` |
| Gallery entries | `resources/js/design/gallery/<Component>.gallery.vue`, loaded by glob at `/dev/gallery` |
| Seeds | `database/seeders/<Area>Seeder.php`, all called from `DatabaseSeeder` by glob |
| Requirement test names | `it('NUT-003 …')` in Pest and Vitest |

## Server

- Laravel 13, PHP 8.4 (raise to 8.5 once the gate host has it), MySQL 8, Redis (cache, queues, the AI progress store).
- MySQL keeps its default REPEATABLE READ, so a plain read inside a transaction that has read before sees that first view. A workout write (`workout_feedback_answer`, `workout_finish`) therefore locks its mesocycle row for update and reads everything after with shared locks (`WorkoutSnapshot::lockFor()`), which return the latest commit: one workout write runs at a time per mesocycle, and it sees what the one before it saved.
- Auth: Sanctum. The PWA uses SPA cookie auth on the same domain. Tokens (CAP-021) are Sanctum personal access tokens whose abilities are capability names.
- Queues: `default` and `agent` (AGT setup defaults), each with its own worker.
- Scheduler: the package's stale-turn reaper, pruning of `audit_records`, the old audit outbox rows and the `cleared_coach_turns` older than two days before the current month (daily; those days are kept for a day counted after a time zone change, which reaches back up to 48 hours, and for a user's local day that began before the app's month, SET-012) and of expired undo tokens (hourly, CAP-013). Every minute, `reminders:send` sends the reminders that fell due (NOTIF, below), and daily the sent-reminder log is pruned to two days. Pruning of the idempotency table is planned but not scheduled yet.
- Web Push: VAPID keys in env, sent with `laravel-notification-channels/webpush`. The server decides when each notification is due.
- Reminders (NOTIF-001 to NOTIF-007): `ReminderPlanner` (`app/Domain/Notifications`) turns a user's days, read through `TodaySnapshot` so they match the Today screen, into reminders with a due time. Every minute `reminders:send` runs a cheap set-based check (`ReminderDays::mayHaveDue`, four queries per time zone in each chunk of 500 users) over every source of a due time: 09:00 on a plan-week start (the plan start or the review weekday, for a user with a morning switch on, until a pass has judged that morning: the pass writes a `reminder_mornings` row whether or not it sent anything, so `sent_reminders` holds only reminders that went out), the weigh-in schedule, the targets' meal times, and the training days' and session rows' starts. It queues a `SendUserReminders` job only for a user with a time in the window, one job per user, so one user's data cannot hold up the others. The job checks again before it reads the plan through `TodaySnapshot`. It then sends that user's reminders due in the last 10 minutes, each once: a row in `sent_reminders` with the reminder and its due time claims it in the transaction that queues the push, so a push that cannot be queued rolls the claim back (on Redis, a commit that fails after the push was queued can send it twice). A reminder already sent since it fell due is not sent again, so a meal or session moved earlier after its reminder went out is not reminded twice; one moved to a time after that send is reminded again at its new time. Each job judges what is due when it runs, so no push is queued for a reminder more than 10 minutes late, also when the queue is behind; the push then waits its turn on the `default` queue, so a queue that is behind delays it further. A job run more than 10 minutes after its pass is dropped whole and logged. Due times are the user's wall-clock times (SET-012, "Days and time zones"): the pass turns the real instant into the user's clock once, so a Brisbane user's 07:00 weigh-in reminder goes out at 07:00 in Brisbane. The switches are rows in `notification_prefs`; a type with no row keeps its default (meals off). Every re-target saves through `RetargetDays::save`, which sends NOTIF-006's notice after the write commits. The notice is best effort: a failure is reported and never fails the re-target. Each notification opens `/today?date=YYYY-MM-DD` (NOTIF-011): a weigh-in reminder adds `&open=weigh-in` and a workout reminder `&open=workout`, which the Today screen must honour to open the weigh-in sheet or the workout.
- Mail: one-time sign-in codes, password reset.
- Every table holding user data has `user_id` as `foreignId()->constrained()->cascadeOnDelete()`, so `account_delete` removes everything (ACC-005), as in rp-clone.

## Capabilities

- Packages: `rawphp/laravel-capabilities` and `rawphp/laravel-capabilities-ai`, the same version (lockstep), required from the package repos by VCS at a tag, with exact versions in `composer.lock`. Never a path repo or `dev-main` in the committed `composer.json`. Switch to Packagist once the packages are published.
- `laravel/mcp` for product MCP (AGT-040). `laravel/ai` is not installed. The core `agent` surface is still on, with `require_package` false, because capabilities-ai's turn tool calls invoke as `caller=agent` (AGT-004). `CAPABILITIES_SURFACE_AGENT=false` stops them.
- Messaging (`rawphp/laravel-capabilities-messaging`) is not installed.
- One host provider, registered last. It binds the authorizer (CAP-020) and loads every capability class (below); there are no module registrars. It also binds the agent's seams in capabilities-ai: the tool catalog (`RegistryToolCatalog`, AGT-003), the conversation context provider (`CoachContext`, AGT-011) and the turn claim (`MeteredTurnClaim`, which keeps each turn's token total for AGT-005). `CoachContext` sends the instructions first and the same on every turn, so Anthropic caches them with the tools (AGT-024). The conversation follows, then the turn's context, then the user's message. The context is the time and a few reads (`TurnContext`), invoked as the agent so the authorizer decides each one. The instructions are `resources/coach/instructions.md` plus the list of destructive tools, taken from the registry. `HostBus` holds an agent tool call's turn ULID in `CurrentTurn` for the length of the invoke, so `AuditRecordWriter` stores it on the row (`audit_records.turn_ulid`) and `coach_changes_list` returns a turn's writes as change cards, each opening the tab `ChangeScreens` names (AGT-013).
- Registration has one path: one class per capability, `app/Capabilities/<Module>/<StudlyName>Capability.php`, which returns its fluent definition. The host provider loads every such file, sorted, and fails boot on a duplicate name. No attributes and no second discovery path. A new capability is a new file, so parallel work never edits a shared registrar. Each definition names its input DTO (the only schema source), its `run()` (which calls an Action), its surfaces, its CLI domain and verb, `affects` (CAP-011), and the flags `reversible` and `destructive`.
- Enabled surfaces: `http`, `job`, `mcp`, `cli`, `agent`. The Vue app uses `http`; the in-app agent's tool calls use `agent`. Every capability declares `HostDefinition::SURFACES`.
- Package defaults are overridden on day one, per `mesoprep/docs/capabilities-host-lessons-learned.md` section 3, item 14: see 17-agent.md, "Setup defaults". Published config files use no `env()` calls inside closures, so `config:cache` works (lessons item 15).
- Freeze tests from day one: registration smoke (every capability registers on its declared surfaces), schema snapshots, the UI and agent parity test (CAP-030), CLI map completeness, and `php artisan capabilities:integration-health` passing in CI.
- Adding a capability: the DTO, the Action with its unit tests, the capability class, a schema snapshot. Nothing else.
- Rails live in `app/Capabilities/Support/`. A definition starts from `HostDefinition::read($name)` or `HostDefinition::write($name, affects: [...])`. A write returns `WriteResult` (CAP-010), with read keys from `ReadKey` (CAP-017). The package catalog has no `affects` field, so each entry is a catalog tag `affects:<read name>` (CAP-011). Statuses use the `Tone` enum (CAP-018). Creates take the row's ULID through `UsesClientUlid`, and their input DTO declares it with Laravel's `ulid` rule (CAP-019). A freeze test checks that every `affects` entry names a registered read. A reversible write passes `reversible: true`, which adds the catalog tag `reversible`; `HostDefinition::register()`, which the host provider registers every capability through, then runs it inside `UndoLog::record()` by replacing the builder's `run`, so the tag and the recording cannot drift apart, and no capability calls `record()` itself (a freeze test). That records every row the write changes through Eloquent and returns the undo token (CAP-013). A reversible write that changes rows any other way (the query builder, a mass write, or a delete that would cascade to rows it did not delete first) fails instead of returning a token, because undo would not see those rows. DayTarget keeps its meal index through DayTargetMeal models, and a removed meal deletes its plan through models first, so re-targeted meals are undone with the day. Each row's state before the write is read from the database with a lock, not taken from the model, which may be out of date. `undo_apply` runs the `ApplyUndo` Action (`app/Actions/Undo/`), which shares `UndoRows` with `UndoLog`; it reads the recorded rows with a lock and refuses, before restoring anything, when one changed since or a row added since points at a row it would remove; a row added since that holds the unique key of one it would put back refuses it too, and the transaction rolls back what was put back. It restores only the columns the write changed, and retries a step that breaks a constraint after the others, so a write that replaces a row under the same unique key can be undone. Writes to the framework's own tables (cache, queue, sessions) do not count as changes outside models. The foreign keys it checks are read from the schema once per migration and cached. A caller that is not a signed-in user (a job's system actor) is refused by the package and `HostAuthorizer` before a reversible write or `undo_apply` runs, since only a user can hold a token. The hourly `model:prune` removes expired tokens; a pruned token answers 404 like an unknown one. `undo_apply` affects `*` (`HostDefinition::ANY_READ`), and the freeze test refuses `*` from any other write: it can change any read, and its result's `invalidates` names the ones it did.
- The authorizer (`app/Capabilities/HostAuthorizer.php`) runs CAP-020's five steps in order. A capability whose input names existing rows implements `OwnsInput`, which returns a query of the rows the user owns for each ID field. `HostBus` runs each invoke inside `Ownership::watch()`, which reports whether an ID failed that invoke's check, and answers that refusal 404. Known gap: the package's `Authorizer` can only answer 403, so the job and MCP surfaces, which call the registry directly, still answer 403. It closes when the package lets an authorizer deny with a code, and then the watch goes. Billing binds `BillingGate`: `NoBilling` allows everything with billing off, and with it on `StripeBilling` refuses a lapsed user's writes except `account_delete`, `token_revoke`, `push_unsubscribe` and the billing capabilities, lets a user who has never subscribed make only onboarding answers and those same exemptions until a trial starts (no free tier), and (through `AgentGate`) a lapsed user's new Coach message (SUB-022, SUB-003, SUB-024). `ConfirmationPolicy` is the `AgentPolicy`. It refuses the agent a capability tagged `destructive` (`HostDefinition::write(destructive: true)`) unless the user message the turn answers is a single line `confirm: <name><input JSON>` whose input, read through the capability's input class, is that call's input (AGT-010); key order, spacing inside the JSON and omitted defaults do not matter, and a line split after the colon or inside the JSON confirms nothing. The policy also refuses caller `mcp` every destructive capability, because an MCP call has no turn to confirm it (AGT-040). The host's `TurnLinkingStore` writes each turn's ulid into the meta of the user message it was created for; the policy and the context read that link through `TurnMessages`, and a message without a turn is never a turn's message. The line must be the whole message, so a pasted copy of the Coach's reply is not consent: until the chat has a confirm control (V_COACH), which must send exactly the Coach's line as the whole message, the user sends the line by itself. No token may call a CAP-031 exclusion (the list is `app/Agent/Exclusions.php`), whatever its scope, and no Coach route takes a token (`AgentGate`), because the Coach's turns run as the user with no scope (CAP-023). An output that implements `ShownOnce`, such as `token_create`'s, keeps its secret out of the idempotency store, so a replay answers without it.
- `HostBus` replaces the package's `CapabilityBus`. It rewords the package's own error messages in plain words (CAP-012) and answers an `Idempotency-Key` reused with different input with 422 instead of the package's 409 (CAP-014). Over http it invokes with the caller derived from the credential and ignores a claimed `X-Capabilities-Caller`. When the package's rate limit refuses a write after claiming its idempotency key and before running it, the package stores that refusal under the key for the whole TTL; `HostBus` gives the key back (`IdempotencyKeys::releaseRateLimited` expires that stored refusal), so the outbox sends the write again under the same key once the limit allows (OFF-011). `IdempotencyKeys` holds the store identity (`default-tenant`, the actor, the capability, the key) that `AcceptOccurredAt` also looks keys up by. Known gap: the job and MCP surfaces call the registry directly, so a rate-limited invoke there still spends its key. It closes when the package stops storing a refusal it gave before the run, and then `releaseRateLimited` goes. A run that refuses one input field throws `FieldRefused` (for a choice only the run can check, such as `phase_transition_complete`'s template against what the plan offers); the package answers any run exception with a message-only `domain_error`, so `HostBus` turns that answer into a 422 whose violation names the field, and gives the key back (`IdempotencyKeys::releaseRefused`), since the run wrote nothing; as with the rate limit, that key can then be sent again with any input. The job and MCP surfaces keep the message without the field until the package lets a run name one.
- `AuditRecordWriter` replaces the package's audit outbox writer. From then on every audited invoke (every write, including one the authorizer refuses; reads and 422s are not audited) is one `audit_records` row with the user, the channel, the redacted input and the result. The package's `capabilities_audit_outbox` table stays as it is: its existing rows are kept and not migrated, and nothing writes to it again. The pipeline calls the writer inside the invoke, not from a queue, so the row is written while the request's credential is the invoke's. `Channel` is one of CAP-022's five. It comes from the caller: `agent`, `mcp` and `cli` are their own. A `job` or `artisan` invoke is the server itself, not a way a person reaches the app, so its row is kept with the user and no channel, and Activity does not list it. Over http it comes from the credential: the session is `ui`, a token made with the Health bridge scope is `bridge`, and any other personal access token is `cli`. Any other caller's row is kept with no channel and the missing mapping is reported, rather than guessed as `ui`. An approval or tool-boundary event carries no caller, so its row has no channel. A failed insert is reported to the exception handler (best_effort audit mode). `activity_show` lists the user's last 100 invokes, approval and boundary events excluded.
- Audit retention is 90 days (build plan, AUDIT): the scheduler prunes `audit_records` daily, as above, and with it the rows the package wrote to `capabilities_audit_outbox` before `audit_records` existed (`AuditOutboxRow`). That table is the package's, so it is never altered or dropped. Like every user table, `audit_records.user_id` cascades on account delete (ACC-005). The outbox rows name the user only in their payload, so `User` deletes them with the account.

## Client

- Vue 3, TypeScript, Vue Router, Tailwind CSS 4, Vite, `vite-plugin-pwa` (Workbox, `injectManifest`), `idb` for IndexedDB. No Pinia. App state is the read cache plus the outbox, both behind one module.
- `app/call.ts` is the only way the app talks to the server, but for the Coach chat: `app/chatTransport.ts` calls the four capabilities-ai chat routes outside the capability rail (history, message, cancel and a turn's events, AGT-021). `read(name, input)` is stale-while-revalidate through the cache (OFF-003). `write(name, input, { label, creates })` adds to the outbox (`app/outbox.ts`, IndexedDB) and returns once the entry is stored (OFF-006); screens mark its reads with `isPending` and show its input with `pendingInput` (OFF-008, OFF-009). `writeNow(name, input)` is for what needs the network (OFF-031): it waits its turn behind the outbox, is never kept, and resolves with the server's answer. Components never call `fetch`.
- The read cache is per user. `NameCacheOwner` (web and api groups) names the signed-in user in a readable, opaque `bb_cache_owner` cookie and removes it when no one is signed in. `app/cache.ts` opens one IndexedDB database per owner, none without one, and deletes the others where the browser can list them.
- The CAP-001 boundary: `resources/js` may not import date math beyond formatting, and no component may compute a value from two server numbers. The lint rule `no-business-rules` (`eslint/no-business-rules/README.md`) checks part of it: `fetch` outside `app/call.ts` and `app/chatTransport.ts`, and arithmetic between two capability payload values inside `features/`. Review checks the rest, including date-math imports and arithmetic outside `features/`.
- One rule is mirrored on the client, by design: when a muscle's feedback falls due (FB-001). `workout_show` names the exercise each sheet is asked after (OFF-020), and the server marks it due once every set of that exercise is logged or skipped (`App\Domain\Progression\FeedbackSheets`). Offline the server has not seen the sets the user has logged since, so `features/workout/feedbackDue.ts` applies the same test to the sets read and their newest waiting log or skip, and only while one of them waits; otherwise the server's `is_due` stands. It does not count sets added or deleted, or exercises reordered, offline. The server checks again when the answer replays, and a refused answer goes to the couldn't-sync tray (OFF-011).
- Vite dev server port **5283**, recorded in `~/EA/projects/AGENTS.md`. Local site `bodybuilder.test` through Herd.

## Walking through time

Carried over from rp-clone so a tester can live through weeks in minutes. Local and testing environments only.

- The client has one clock: the device clock plus an offset kept in local storage, set from Settings → Developer (SET-011): a date picker, "+1 day", "+7 days", "Clear".
- With an offset set, every request sends `X-BB-Now: <ISO 8601>`, and outbox entries use the shifted time for `occurred_at` (OFF-007). Replay sends it as `X-BB-Occurred-At`; `App\Http\Middleware\AcceptOccurredAt` (capability routes, after auth) refuses one more than 7 days old or 5 minutes ahead, unless the server already holds an answer under the write's idempotency key, and `App\Support\OccurredAt::get()` returns it, or now when the request carried none. `App\Actions\Meals\CheckInMeal` reads it for a meal check-in's time (OFF-030, #239), and `workout_start` and `workout_finish` record a session's start and end from it, normalised to UTC (WO-010). A date-tied write that does not call it still lands at server time when replayed. The others arrive with their own issues: logging, skipping and editing sets (#83), and weigh-ins and steps (#88). Each must use `OccurredAt::get()` for the day or time it records, never the clock. The idempotency TTL is 8 days, longer than any replay.
- `App\Http\Middleware\UseRequestClock`, first in the `api` and capability route groups, calls `Carbon::setTestNow` for that request when `APP_ENV` is `local` or `testing`. Every other environment ignores the header.
- The clock walks forward only. The server ignores an `X-BB-Now` earlier than its own clock, and the picker starts at today: a past instant would date the session and XSRF cookies in the past and sign the user out.
- The in-app agent's turns run on the queue, so the turn records the request's clock and the job sets it before running tools.
- The queue keeps real time. The `database` and `redis` queues (`App\Queue`) take every time they stamp from the clock before the walk: a push, a delay, a bulk push and a walked job's own `release()`. A normal worker therefore finds the job.

## Days and time zones

Each user has an IANA time zone in `users.time_zone` (SET-012), `UTC` until the device sends one. On load, the signed-in shell sends `Intl.DateTimeFormat().resolvedOptions().timeZone` through `time_zone_set` when the server may not have it (`app/timeZone.ts`): the first time, when the device is in another zone, for another user, or once the last send is a day old. The zone therefore follows the device when the user travels, and each device sends about once a day. The write is audited but left out of the Activity list (`ListActivity::HIDDEN`), since the app makes it, not the user.

- The date convention does not change. A calendar day is midnight UTC of its `Y-m-d` (`TrainSnapshot::day`, `DayPlans::day`), and a wall-clock time is read as UTC, as `ClockTime` and the plan's meal and session times are. Calendar-day maths stays in UTC.
- `App\Support\UserDay` is the one place a real instant should become a user's day or clock time. `now()` is the user's wall-clock time labelled UTC, `clockOf()` the same for a stored instant such as a session's `actual_start_at`, `today()` and `dayOf()` their days, and `bounds()` the first and last second of a local day as UTC instants, for querying stored instants such as `weighed_at`. It reads `CarbonImmutable::now()`, so the time-walk clock applies. `TrainSnapshot` carries the user's zone, and `startedAt()` reads a session's start through it.
- Moved so far: `TrainSnapshot`, `TodaySnapshot` and `PlanSnapshot`'s today, Today's now, the time a meal checked in with no time records (`CheckInMeal`), the Adjust and Redistribute sheets and their writes, Today's weigh-ins and current weight, where a started or skipped session sits on Today, the week strip, Train and the missed-weeks prompt, and the workout read keys (`WorkoutReads`, `WorkoutDayReads`, whose calendar carries the user's today and zone), the Weigh-ins screen, its missed days, the days weigh-in, steps and schedule writes refresh, reminder due times, the Nutrition tab's undated read, the exercise screen and exercise name keys, the eligibility re-check screen and its keys, a phase's weights and the phase-end summary (`PlanSnapshot`, `PhaseChanges`), the Coach's turns today and when its daily cap resets (`AgentStatus`, `Allowance`; the monthly token cap stays in the app timezone), the Coach's "Now" line and last week (`TurnContext`), the Progress chart, waist line, summary and phase history, and the plan rebuild (`RebuildTargets`), whose today and session starts are the user's. `PhaseWarnings` keeps its UTC zone, since it takes calendar days and Plan's today. `TodayReads` drops the undated `today_show` and `week_show` for the signed-in user's today and for the UTC day, so callers that still name today in UTC keep Today fresh.
- Not moved yet, still counting UTC days, each waiting on its own follow-up issue: the weekly review's weigh-in days (`ReviewAnalysis`, and the Progress review card, `ProgressWeekPayload::reviewCard()`, which shares its rule), and a Settings row showing the zone. Any other clock read (`CarbonImmutable::now()`, `Carbon::now()`) that names a day, and any other stored instant bucketed into days, is also still UTC.
- The Coach's daily turn cap (AGT-005) runs in the user's local day (SET-012), and a time zone change gives no fresh daily allowance. `SetTimeZone` stamps `users.time_zone_changed_at` on each change. For 24 hours after it, the day's turns count from 24 hours before the change, and the cap resets 24 hours after the change, or at the new local midnight if that is later and the new local day's own turns reach the cap. A user who changes zone again after those 24 hours can still shift the day, so the monthly token cap, which runs in the app timezone, is what bounds the instance's LLM bill: set `AGENT_TOKENS_PER_MONTH`.

## Tests

The `~/EA/projects/AGENTS.md` testing rules apply: unit first, thin feature tests for HTTP and auth seams, and schema changes last.

- Pest for PHP. Domain and Actions are unit tests with mocked I/O. Feature tests cover the capability HTTP seam, cross-user 404s, token scopes, idempotency and the Stripe webhook.
- Warp (`rawphp/warp`) with `WARP_MODE` and `WARP_DB` on, against MySQL, from M0.
- Vitest for components and `call.ts` (cache, outbox order, retry, the 4xx tray, the user switch in OFF-012).
- Playwright on a 390 × 844 viewport for three journeys and one layout check, plus the screen audits below:
  1. stepper onboarding to the first finished workout;
  2. the offline workout (OFF-050);
  3. chat onboarding against the fake LLM driver;
  4. the shell layout at 390 × 844 and 1440 × 900 (PLT-002, NAV-003).

  `tests/e2e/` also holds the audits of Today, Workout and Meal: Lighthouse accessibility and best practices, the manifest's installability (PWA-051), and a cold start from cache on slow 4G with a 4× slower CPU (PWA-004). `scripts/e2e-server.sh` serves the built app on a throwaway MySQL (found as Warp finds it), seeded by `Tests\Support\AuditSeeder`.
- Tests are named after the requirement they prove, for example `it('PROG-024 recalculates reps at equal effort')`.
- Agent evals (AGT-050) live in `tests/Agent/` and run against the fake driver in the gate. The real-model run is a separate command, run before a release.
- `bash scripts/check.sh` is the gate. It runs, stopping at the first failure:
  1. the spec-ID check (every requirement ID named in a test exists in `docs/spec/`);
  2. `pint --test`;
  3. `phpstan`;
  4. `pest --parallel`;
  5. `eslint resources/js` (the CAP-001 rule `no-business-rules`, `eslint/no-business-rules/README.md`);
  6. `vue-tsc --noEmit`;
  7. `vitest run`;
  8. `vite build`;
  9. Playwright.

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
| Meal kcal after a hand edit | NUT-030 says meal targets add up to the day; NUT-035 lets a hand-edited meal take most of it. `App\Domain\MealSplit\MealSplit` keeps rp-clone's rule: the other meals share whatever kcal is left, and take their food's kcal once nothing is. On a 1500 kcal day where the other two meals hold 250 kcal of food each, fixing one meal at 1400 kcal shows them as 50 each, at 1499 as 1 and 0, and at 1500 as 250 each (the test `NUT-030 NUT-035 a fixed meal that takes most of the day leaves the others what is left`). Decide whether the other meals should keep a floor (for example half their food's kcal, overspending the day) before Today and the meal screen show these numbers. |

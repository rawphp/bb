# Bodybuilder: product spec

Status: draft 1, 2026-11-01. Platform: an installable web app (PWA) that works offline, backed by Laravel. Open source (MIT).

Bodybuilder is one app for training and nutrition, with one plan driving both. It merges two earlier projects:

- **rp-clone** (`~/EA/projects/rp-clone`, `rawphp/rp-clone`). It supplies the product: every RP Diet Coach and RP Hypertrophy feature plus the integration rules, in 467 requirements, and the screens. Files 01 to 14 and 99 in this folder are copied from its `docs/spec/` (draft 3, 2026-10-09) and amended where the platform changed.
- **mesoprep** (`~/EA/projects/mesoprep`, `original-solutions/mesoprep`). It supplies the agent: an in-app Coach that can do anything through the same capability layer the app uses, built on `rawphp/laravel-capabilities` and `rawphp/laravel-capabilities-ai`. Its retrospective `docs/capabilities-host-lessons-learned.md` is required reading. Sections 3 and 4 of it are the host setup checklist for this project.

Neither old app's code is copied. The rules are ported and the screens are rebuilt in Vue.

## What changes from rp-clone

| rp-clone | Bodybuilder | Where |
|---|---|---|
| iPhone app (SwiftUI) | PWA in Vue 3, installable to the home screen, phone-first | PLT, 18-native-feel.md |
| Rules in Swift (`RPCore/Domain`, about 9,000 lines) with a thin API | Every rule on the server. The client only displays | 15-capabilities.md, architecture.md |
| Offline out of scope | Offline in scope: cached screens plus a write outbox | 16-offline.md |
| No AI coach | Optional agent that can do everything the user can | 17-agent.md |
| App Store subscription | Self-hosted: no billing. Hosted version: Stripe | SUB |
| Sign in with Apple, Google, email and password | Email and password, plus email one-time code | ACC-001 |
| Apple Health reads and writes | Manual entry, plus an optional iOS Shortcut that posts weight and steps | INT |
| Steps only from Apple Health | Steps can be typed in (from mesoprep) | WT-020 |
| No waist measurement | Optional waist with each weigh-in (from mesoprep) | WT-021 |

## Files

| File | Covers | Prefix |
|---|---|---|
| [01-account-safety.md](01-account-safety.md) | Account, billing, platforms, health screening, safety rails | ACC, SUB, PLT, SAFE |
| [02-onboarding.md](02-onboarding.md) | Unified setup, first-run tour | ONB |
| [03-plan-and-phases.md](03-plan-and-phases.md) | The plan, diet phases, mesocycle alignment, phase transitions | PLAN, PH |
| [04-schedule.md](04-schedule.md) | Today screen, week strip, meal and workout placement, busy periods | SCH |
| [05-nutrition-targets.md](05-nutrition-targets.md) | Energy, macros, per-meal distribution, weekly calorie goal | NUT |
| [06-food-and-meals.md](06-food-and-meals.md) | Food database, scanning, meal builder, check-ins, recipes, shopping list | FOOD, MEAL, DAY, RCP, SHOP |
| [07-mesocycles.md](07-mesocycles.md) | Templates, Meso Builder, priorities, exercises, length, deload | MESO, EX |
| [08-workouts.md](08-workouts.md) | Workout logging, set types, feedback questions | WO, FB |
| [09-progression.md](09-progression.md) | Volume, load, rep and RIR rules, deload, recovery flags | PROG |
| [10-weekly-review.md](10-weekly-review.md) | The single weekly review, calorie adjustment, fatigue | REV |
| [11-progress-and-weigh-ins.md](11-progress-and-weigh-ins.md) | Weigh-ins, trend weight, steps, charts, history | WT, ANA |
| [12-settings-integrations-content.md](12-settings-integrations-content.md) | Shortcuts bridge, notifications, settings, learning content | INT, NOTIF, SET, LRN |
| [13-shared-ui.md](13-shared-ui.md) | Navigation and the shared design system | NAV, UI |
| [14-defaults-and-tables.md](14-defaults-and-tables.md) | Every number the requirements refer to | TBL |
| [15-capabilities.md](15-capabilities.md) | One capability per action; UI, agent, MCP and CLI all use it | CAP |
| [16-offline.md](16-offline.md) | Screen cache, write outbox, replay, pending state | OFF |
| [17-agent.md](17-agent.md) | The in-app agent, agent onboarding, MCP | AGT |
| [18-native-feel.md](18-native-feel.md) | What makes the PWA behave like a native phone app | PWA |
| [19-views.md](19-views.md) | Every rp-clone SwiftUI view and the Vue view that replaces it | VIEW |
| [99-coverage.md](99-coverage.md) | Every researched RP feature mapped to a requirement | |

Architecture decisions every issue follows: [../architecture.md](../architecture.md).

## How to read the requirements

The tags are rp-clone's, plus one new tag:

| Tag | Meaning |
|---|---|
| [D] | Behaviour copied from RP Diet Coach |
| [H] | Behaviour copied from RP Hypertrophy |
| [M] | RP methodology from articles, videos or books, which neither app implements as described |
| [I] | Integration behaviour. This is what makes it one app |
| [G] | Best guess. RP does not publish this behaviour, so we chose a value |
| [B] | New in Bodybuilder: platform, offline, agent or OSS behaviour |

"Must" means required for v1. A requirement amended for Bodybuilder ends with a note: *Bodybuilder: what changed.* "Deferred" means not in v1.

## Core model

Unchanged from rp-clone:

```
Plan (one per user, a timeline)
 └─ Phase  (fat loss | maintenance | muscle gain | mini-cut), N weeks, target rate
     └─ Mesocycle  (4-8 weeks, last week = deload), training days, muscle priorities
         └─ Week  (one calorie goal, one review)
             └─ Day  (rest | training: light/moderate/hard)
                 ├─ Workout  (exercises, sets, feedback)
                 └─ Meals  (targets, foods, check-ins)
```

Four rules, from rp-clone, hold the two halves together. Each one is a requirement later in the spec.

1. **The phase sets both halves.** Fat loss sets every muscle to Emphasize, raises MEV, lowers the MRV ceiling and makes load steps smaller. Muscle gain allows specialisation. Maintenance means low-volume resensitization training. (PH, MESO, PROG)
2. **Workouts feed nutrition.** Planned workouts, with their times and lengths, appear on the day's schedule. How hard a day is comes from the session's planned sets. That sets the day's calories and puts carbs before and after the session. Moving, skipping or finishing a workout re-targets the meals still to come. (SCH, NUT)
3. **Deloads and diet breaks are the same week.** Every deload week runs at maintenance calories, and in a cut that makes it a diet break. Choosing a diet break in the weekly review offers to turn that week into a deload. (PLAN, REV)
4. **One weekly review reads both halves.** Weight trend, food adherence, training performance and recovery feedback are judged together. When lifts fall during a cut, the app asks about diet fatigue before it cuts volume. (REV)

Bodybuilder adds three rules of its own:

5. **The server decides; the screen shows.** Every number the user sees comes from the server. That includes targets, remaining macros, set targets, review outcomes and trend weight. The browser holds no business rule (CAP-001).
6. **Every action is a capability.** Whatever the user can do from a screen, the agent can do by being asked, and the reverse, because both call the same capability (CAP-002, AGT-001).
7. **The gym has no signal.** A workout started online can be finished offline, and nothing the user typed is lost (OFF-001).

## Scope

In scope for v1: everything in files 01 to 19 not marked Deferred.

Out of scope for v1, from rp-clone's list and still out:

- rest timer and superset set type (WO-030);
- long-term strength charts beyond per-exercise history;
- trainer or client portal, coach messaging between people;
- wearables;
- micronutrient tracking;
- data export (users own the database on a self-hosted instance).

Out of scope for v1, new:

- native iOS or Android shells (Capacitor and similar). The PWA has to stand on its own first;
- importing data from mesoprep or rp-clone. Both keep running until Tom switches;
- passkeys (a good v2 candidate);
- voice (mesoprep's text-to-speech);
- offline food search and offline AI (16-offline.md lists what works offline);
- more than one AI provider. The agent uses Anthropic through `laravel-capabilities-ai`.

## Milestones

Each milestone ships something usable. The agent comes after the capabilities it calls.

| # | Ships | Requirements |
|---|---|---|
| M0 | Laravel 13 app, auth, the capability rail, screen cache and outbox, app shell (tabs, sheets, transitions), design system gallery, time-walk clock, self-hosting guide | ACC, CAP, OFF-002 to OFF-012, OFF-051, PWA, UI, NAV, VIEW (design system and shell rows) |
| M1 | Onboarding (stepper), screening, plan and phases, Today with week strip | SAFE, ONB, PLAN, PH, SCH (placement only) |
| M2 | Training: templates, Meso Builder, workouts, feedback, progression, offline workout | MESO, EX, WO, FB, PROG, OFF-020 to OFF-023, OFF-050 |
| M3 | Nutrition: targets, food search and scan, meals, check-ins, Day Balance, recipes, shopping | NUT, FOOD, MEAL, DAY, RCP, SHOP, rest of SCH, OFF-030, OFF-031 |
| M4 | Weekly review, weigh-ins, steps, Progress tab, notifications, Learn | REV, WT, ANA, NOTIF, LRN, SET |
| M5 | The agent: chat, agent onboarding, MCP | AGT |
| M6 | Optional modules: Stripe billing, Shortcuts bridge | SUB, INT |

## Name

Bodybuilder (decided by Tom, 2026-11-01). Repo folder `~/EA/projects/bb`. No GitHub remote yet.

## Decisions made for Bodybuilder

| Decision | Choice | Why |
|---|---|---|
| Client | Vue 3 single-page app served by Laravel, not Inertia | Inertia needs a server round trip on every page visit, which rules out offline |
| Where rules live | Only on the server | Tom's brief. It also means the agent, MCP and the UI can never disagree |
| Offline model | Cached screens plus an outbox of capability calls. Offline, derived numbers show as pending | The only offline model with no rules in the browser (decided by Tom, 2026-11-01) |
| Distribution | Free to self-host, plus a paid hosted version run by the project, from the same public code | Decided by Tom, 2026-11-01. Hosted-only behaviour is configuration (SUB-023) |
| Billing | Stripe module, off by default, on for the hosted version | Self-hosters pay nothing and see no paywall |
| Health data | Typed in, or posted by an iOS Shortcut | A PWA cannot read HealthKit |
| Agent | Optional; needs the instance's Anthropic key. Capped per user on the hosted version (AGT-005) | The app must be fully usable without an LLM bill |
| Existing data | Fresh start | Decided by Tom, 2026-11-01 |

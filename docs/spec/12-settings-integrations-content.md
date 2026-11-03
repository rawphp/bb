# 12. Integrations, notifications, settings and learning content

## Health integrations (INT)

A PWA cannot read or write Apple Health or Health Connect. Bodybuilder covers the need with manual entry (WT-020) and an optional bridge. The bridge is an iOS Shortcut the user installs; a personal automation runs it, it reads Health and posts to the capability API. It uses the same capabilities as the screens, so the server applies the same rules.

- **INT-001** [B] Settings must offer a "Health bridge" page. It creates a personal access token limited to `weigh_in_log` and `steps_log` (CAP-023), shows it once, and links to a shared Shortcut that posts yesterday's steps and the latest body weight (with its time) using that token. The page must say what the token can and cannot do, and allow revoking it.
- **INT-002** Deferred. [G] Android has no Shortcuts equivalent built in. Android users type weight and steps (WT-020), or use a third-party automation app that can post to the same capability API.
- **INT-003** Dropped. [G] Writing completed workouts to Apple Health needs native code. Revisit if a native shell is ever built.
- **INT-004** [D][B] The Health bridge page must show when the bridge last posted, and what, so the user can tell if the automation stopped running.
- **INT-005** [I] There is no link to another app to set up. Training and nutrition share data inside the app. Differs from RP.

## Notifications (NOTIF)

Notifications are Web Push (iOS 16.4 or later, installed to the home screen; Chrome and Firefox anywhere). The server decides when each one is due and sends it. The browser only shows it.

- **NOTIF-010** [B] The app must ask for notification permission only after the user turns on a notification type in Settings or taps "Remind me" in onboarding, never at first launch. On iPhone, Settings must explain that notifications need the app on the home screen.
- **NOTIF-011** [B] A notification must open the screen it is about (WT-002: the weigh-in sheet; NOTIF-003: the workout).


- **NOTIF-001** [D] Weigh-in reminders at each scheduled weigh-in time.
- **NOTIF-002** [D] Optional meal reminders at each meal time. Off by default, since RP users found frequent check-in prompts annoying.
- **NOTIF-003** [I] Workout reminder 60 minutes before a planned session, with the pre-workout meal's status ("Meal 2 not checked in yet").
- **NOTIF-004** [D] "Weekly review available" on review day.
- **NOTIF-005** [I] "Build your next mesocycle" at the start of each deload week.
- **NOTIF-006** [I] A notice when a re-target changes meals the user has already planned foods for (SCH-050 to SCH-054).
- **NOTIF-007** [D] Each notification type must have its own on/off switch in Settings.

## Settings (SET)

- **SET-001** [G] Units: one setting for body weight and loads (kg or lb), and one for height (cm or ft/in). Data is stored in metric and converted for display. Changing units must convert all history. Differs from RP, which locks units per mesocycle.
- **SET-002** [D] Appearance: light, dark or system.
- **SET-003** [D] Consumed totals: include planned meals (default) or checked-in only (DAY-011).
- **SET-004** [D] Profile: name, sex, date of birth, height, body-fat bucket, GLP-1 answer, training experience. Changes recalculate targets from the next day.
- **SET-005** [D] Schedule defaults: eating window, meal count, busy periods, training days and times, weigh-in days, review day, step plan.
- **SET-006** [D] Diet filters (FOOD-027).
- **SET-007** [D][H][B] Subscription and manage plan (SUB-007). Shown only with billing on.
- **SET-010** [B] Health bridge (INT-001), Personal access tokens (CAP-023) and Coach (AGT-020) pages.
- **SET-011** [B] Developer section in local and testing environments only: the time-walk clock (architecture.md, "Walking through time").
- **SET-012** [B] Time zone: each user has an IANA time zone, taken from the device on each app load (default UTC). Today, plan days, reminders and weigh-ins count days in it. Built so far: Today, its week strip and weigh-ins, Train, Plan, the weigh-ins screen, and the Coach's daily turn cap and its time of day; reminders and Progress still count UTC days until their follow-ups land (architecture.md, "Days and time zones").
- **SET-008** [D] Contact support (email) and the help hub.
- **SET-009** [D] Account: sign out, delete account.

## More menu (SET)

- **SET-020** [D][I][B] The More menu must list: Plan and phases, Adjust phase length, End current phase, Weigh-ins, Custom foods, Custom exercises, Saved meals, Recipes, Shopping list, Share progress, Learn, Settings, and sync status (ACC-003).

## Learning content (LRN)

- **LRN-001** [D][H] A Learn section must hold articles and short videos on using the app and the methods behind it. At minimum:
  - how the app differs from a tracker;
  - is it right for me;
  - choosing a goal;
  - each phase type;
  - body fat;
  - weighing in;
  - why weight goes up;
  - meals per day;
  - building meals;
  - check-ins;
  - Day Balance;
  - alcohol;
  - coffee;
  - oils;
  - eating out;
  - mesocycles;
  - RIR;
  - rep ranges;
  - warm-ups;
  - rest times;
  - feedback questions;
  - deloads;
  - not getting pumps;
  - long workouts;
  - missing a day;
  - training while sick;
  - training alongside a sport;
  - home gyms.
- **LRN-002** [I] Learn must include articles on how training and diet work together:
  - training on a cut;
  - deloads at maintenance;
  - maintenance and resensitization;
  - why the app moves your carbs around workouts;
  - what to do when lifts stall in a diet.
- **LRN-003** [D][H] Each feature screen must link to its matching Learn article from a ? icon.
- **LRN-004** [H] Exercise technique videos live in the exercise library (EX-001), not in Learn.
- **LRN-005** [D] The tools needed (gram food scale, bathroom scale, gym access) must be listed in onboarding help and in Learn.
- **LRN-006** [M] Learn and the phase transition screen must tell users to hold off cheat foods for 1 to 4 weeks after a cut ends.

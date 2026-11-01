# 12. Integrations, notifications, settings and learning content

## Health integrations (INT)

- **INT-001** [D] On iPhone, the app must read body weight (with time) and step count from Apple Health, with the user's permission.
- **INT-002** Deferred, not in v1 (iPhone only). [G] On Android, the app must read the same from Health Connect.
- **INT-003** [G] The app must write completed workouts (type strength training, start, end, estimated energy) to Apple Health and Health Connect.
- **INT-004** [D] Health permissions must be manageable from Settings, with a note on what each one is used for.
- **INT-005** [I] There is no link to another app to set up. Training and nutrition share data inside the app. Differs from RP.

## Notifications (NOTIF)

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
- **SET-007** [D][H] Subscription, restore purchases, manage plan (SUB-007, SUB-008).
- **SET-008** [D] Contact support (email) and the help hub.
- **SET-009** [D] Account: sign out, delete account.

## More menu (SET)

- **SET-020** [D][I] The More menu must list: Plan and phases, Adjust phase length, End current phase, Weigh-ins, Custom foods, Custom exercises, Saved meals, Recipes, Shopping list, Share progress, Learn, Settings, and sync status.

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

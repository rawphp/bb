# 04. Schedule (Today)

Today is RP Diet's Schedule screen with RP Hypertrophy's workouts placed on it. It is the home screen. Meals, workouts, weigh-ins and busy periods sit on one timeline in time order.

## Day timeline (SCH)

- **SCH-001** [D][I] Today must list the day's items in time order: weigh-in, meals, workouts and busy periods.
- **SCH-002** [D] Each meal card must show meal name, time, and target calories, protein, fat and carbs, plus the foods planned when there are any.
- **SCH-003** [I] Each workout card must show the session name ("Week 3 · Day 2 · Upper A"), start time, estimated length, number of exercises, total working sets, and a Start button. Tapping it opens the workout (WO-001). After the workout it shows Done and the sets completed.
- **SCH-004** [D] The weigh-in row must show the scheduled time and either the logged weight or an "Add weigh-in" button.
- **SCH-005** [D] Busy periods must show as greyed blocks with their label.
- **SCH-006** [D][I] A day-totals bar at the top must show calories and protein (consumed against target) and the day type (Rest, Light, Moderate, Hard, or Deload). An Adjust button opens DAY-020.
- **SCH-007** [D] A condensed view must collapse meal and workout cards to one line each. The setting persists.
- **SCH-008** [D] Empty meals must show a banner prompting the user to plan foods ("Plan this meal before you eat it").
- **SCH-009** [D] Checking a meal must be one tap on its checkbox (MEAL-040).
- **SCH-010** [D] Swiping a meal card left must show Edit macros and Delete. Long-pressing a meal card must open the meal menu (MEAL-009).
- **SCH-011** [D] The timeline must show the day's step target as a row (for example "Step count target 0-8k") from the step plan, with actual steps when available.

## Week strip (SCH)

- **SCH-020** [D] A week strip above the timeline must show 7 days with each day's target calories, and today highlighted. Swiping changes the week, tapping changes the day.
- **SCH-021** [D] Completed days must show a green dot. Days before the plan start show "–".
- **SCH-022** [I] Training days must show a small dumbbell icon on the week strip. Deload weeks must show a "Deload" label above the strip.
- **SCH-023** [D] The calendar icon must open a month view to jump to any date in the plan.

## Schedule menu (SCH)

- **SCH-030** [D] The schedule menu must offer: Add meal, Add workout, Add busy period, Copy day, Copy week, Optimize schedule, Edit schedule.
- **SCH-031** [D] Add meal must add an extra meal at a chosen time and redistribute the day's macros across meals not yet checked in.
- **SCH-032** [I] Add workout must offer two kinds: a session from the current mesocycle (moves the next planned session to this day), or "Other activity" (name, length, intensity: light, moderate or hard; used for sport or cardio, logged for nutrition only).
- **SCH-033** [D] Add busy period must take a label, start and end time, and the days it repeats.
- **SCH-034** [D] Copy day must copy all planned foods from one day to one or more other days, with the options in MEAL-060.
- **SCH-035** [D] Copy week must copy all planned foods from one week to the next week.
- **SCH-036** [D][G] Optimize schedule must re-place unchecked meals on the selected day according to SCH-040 to SCH-046, and show the change before saving.
- **SCH-037** [D] Edit schedule must open the eating window, meal count, busy periods and training times for this day or every day, with "Apply to: this day / all future days".
- **SCH-038** [I] A day can hold at most one mesocycle session. Moving or adding a session onto a day that already has one must ask for a different day. "Other activity" can share a day with a session. Its energy adds to the day (NUT-003), the day type is the higher of the two, and peri-workout meals are placed around the mesocycle session only.
- **SCH-039** [I][H] Changing training weekdays mid-mesocycle (Settings or Edit schedule) must apply from the next plan week. Keeping the same number of days just re-maps sessions to the new weekdays. Fewer days must ask the user to either restructure the mesocycle from next week (the builder redistributes muscles, and MESO-023 frequency is re-checked) or keep all sessions and let each plan week finish fewer of them, so the mesocycle runs longer. The current week's unchecked days are re-targeted.

## Meal and workout placement (SCH)

- **SCH-040** [I] Planned workouts must come from the current mesocycle. Its sessions, in order, go onto the user's training weekdays at the times set in ONB-011.
- **SCH-041** [D][G] Meals must be spaced evenly inside the eating window, at least 2 hours apart, avoiding busy periods.
- **SCH-042** [M][I][G] On a training day, the app must place a pre-workout meal ending 1 to 3 hours before the session (target 90 minutes), and a post-workout meal starting within 60 minutes after it ends. These two meals may be closer than 2 hours apart.
- **SCH-043** [M][I][G] If a session's estimated length is over 60 minutes, the app must offer an intra-workout shake (a "shake" item tied to the workout). It carries carbs and protein from the day's peri-workout share (NUT-031). Shakes keep their timing relative to the workout when the workout moves.
- **SCH-044** [D] Users must be able to edit any meal's time by hand. A hand-set time stays fixed until the user resets it.
- **SCH-045** [D] Eating windows and busy periods must support night shifts and fasting patterns. That means a window can cross midnight, and the first meal can be at any hour.
- **SCH-046** [I][G] Estimated session length must be calculated as working sets × 2.5 minutes + 10 minutes warm-up, rounded to 5 minutes. The user's actual lengths replace this estimate after 3 sessions of the same day.

## Workout changes re-target the day (SCH)

- **SCH-050** [I] Moving a workout to another time (drag, or edit time) must re-place and re-target the day's unchecked meals immediately.
- **SCH-051** [I] Moving a workout to another day must re-target both days. The source day becomes a rest day.
- **SCH-052** [I] Starting a workout at a different time from planned must update the workout's time to the actual start, and re-target the meals still to come.
- **SCH-053** [I] Skipping a workout (WO-050) must turn the day into a rest day. The new day total is the rest-day target. Unchecked meals share what remains after the checked-in meals, using the rest-day split, and SAFE-010's per-meal floors win over the day total. The calories the workout would have burned are removed, not moved to other days. Adherence for that day is judged against the new target.
- **SCH-054** [I] When a session runs much longer or shorter than planned (more than 20 minutes off), the day's target must update from the actual length for meals still to come.
- **SCH-055** [I] Meals that are checked in, locked, or have a hand-edited target must never be changed by SCH-050 to SCH-054.
- **SCH-056** [I] Every re-target must show a short toast ("Meals updated for your 5:30 PM workout") with Undo.
- **SCH-057** [I][G] A missed training day (no workout logged by the end of the day) must ask the next morning:
  - "Do it today", offered only when today is a rest day. Only that session moves. Later sessions keep their weekdays.
  - "Do it on the next rest day this week", offered when today is a training day and a rest day remains before the plan week ends.
  - "Skip it". The session is marked skipped. The past day's targets and adherence stay as they were, because the food is already eaten.

  A session can never move into the next plan week. If neither move is possible, only Skip is offered.
- **SCH-058** [H] Missing a week or more of training must prompt the user to restart the current week, or end the mesocycle and build a new one.

## Away weeks (SCH)

- **SCH-060** [I][G] Users must be able to mark one or more future plan weeks as "Away" (travel, illness, no gym). For each away week, the user chooses one of these for training:
  - pause the mesocycle, so it resumes where it stopped;
  - make the week its deload, if at least 3 training weeks are done.

  Then they choose calories: rest-day targets on every day, or maintenance on every day. Maintenance is the default during Fat loss, and the week then counts as a diet break.
- **SCH-061** [I][G] Away weeks must not count toward training fatigue (REV-040) or measured expenditure (REV-021). Their weigh-ins are optional. The review for an away week only shows weigh-ins and the next week's preview, and is never blocked by REV-001's weigh-in minimum.
- **SCH-062** [M][G] During Fat loss, the app must warn, and not block, when a week has more than 4 hard Other-activity sessions (HIIT, sport). The warning says too much cardio hurts lifting recovery.

## Rough weeks (SCH)

- **SCH-063** [I][G] Users must be able to mark the current plan week as a "Rough week" from the week strip menu, and unmark it before the week ends. It is for weeks they still train through: poor sleep, stress, a cold. Training runs as planned. Sessions logged in a rough week must not add sets (PROG-010) or raise target weight or reps (PROG-021, PROG-022) at their next occurrence. Set removals (PROG-010, PROG-013) still apply. A deload or away week can't be marked.
- **SCH-064** [I][G] The review of a rough week must recommend Repeat this week, with the reason "Rough week. Holding training and calories." The other calorie options stay. The week still counts toward measured expenditure (REV-021) and fatigue (REV-040), and it moves no dates.

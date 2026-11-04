# 02. Onboarding

One setup covers both halves. RP asks about workouts in the diet app and then makes you build the same training plan again in the training app. Here the training answers build the mesocycle and the schedule at the same time.

Setup shows a step counter ("Step 4 of 13") and a back button on every step.

## Flow (ONB)

- **ONB-001** [D] First launch must show three intro cards explaining the product before sign-up: plan-first meals, training that adjusts to feedback, and one weekly review. The last card leads to sign-up.
- **ONB-002** [D] Setup must save progress after every step. A user who quits mid-setup must resume at the step they left.
- **ONB-003** [D][I] Step 1, goal. The user picks Fat loss, Muscle gain or Maintenance. Help text must say that people who want to lose fat and gain muscle at once should pick Fat loss. There is no separate recomp goal.
- **ONB-004** [D] Step 2, about you: name, sex (male or female), date of birth, height and weight. Units default from the device locale and can be switched on this step.
- **ONB-005** [D] Step 3, health screening (SAFE-001). Failing an item that blocks all targets switches to training-only mode (SAFE-003) and skips the nutrition steps (4, 5, 6, 10, 11 and 13). The goal from step 1 still sets training defaults. Failing only "trying to conceive" with Fat loss chosen must send the user back to step 1 to pick Maintenance or Muscle gain.
- **ONB-006** [D] Step 4, the weight-loss drug question (SAFE-013).
- **ONB-007** [D] Step 5, body fat. The user picks the photo that looks most like them from sex-specific buckets (TBL-001). Help text must say accuracy is not critical and not to use smart-scale body-fat readings.
- **ONB-008** [D] Step 6, rate and length. Fat loss offers "Highest success" (default), "Slow & steady" and "Custom". Muscle gain and Maintenance offer the matching presets in TBL-010. The user sets start date (default tomorrow), end date and target weight. Changing any one of the three recalculates the others.
- **ONB-009** [D] Step 6 must enforce the length and rate limits in TBL-010 and show the recommended range under the slider.
- **ONB-010** [I][G] Step 7, training experience. Three options: Beginner (under 1 year of consistent lifting), Intermediate (1 to 4 years), Advanced (over 4 years). This sets default meso length (TBL-020) and starting volume. Differs from RP: RP Hypertrophy asks nothing.
- **ONB-011** [I][H] Step 8, training days. The user picks which weekdays they train and a start time and expected length for each. Help text must say that 2 to 4 days they can repeat every week beat 5 or 6 they will miss. This step replaces both RP Diet's workout schedule and RP Hypertrophy's day count.
- **ONB-012** [I][H] Step 9, training plan. The user picks one of:
  - a recommended template, filtered to their day count, sex and goal (MESO-010);
  - the Meso Builder (MESO-020);
  - "I'll build it later" (the schedule shows workout blocks with no exercises until they do).

  The app must preselect the recommendation for their goal: full-body for Fat loss, a specialisation template for Muscle gain, a balanced template for Maintenance.
- **ONB-013** [D] Step 10, steps. Typical daily step count as a range per day, with weekday and weekend presets (for example "Weekdays 0-8k, Weekends 8-13k").
- **ONB-014** [D] Step 11, meals. Eating window (first and last meal time), number of meals (2 to 6, default 5) and optional busy periods. The same window can apply to every day or be set per day.
- **ONB-015** [D] Step 12, weigh-ins. Days and a time for each. At least 2 days a week are required to continue ("Too few weigh-ins to continue"). A hint must recommend 4 or more. Help text must say the time only sets the reminder.
- **ONB-016** [I][G] Step 12 must also ask which day the weekly review happens. The default is the day after the last weigh-in day of the week. The review day is also the first day of every plan week (PLAN-008).
- **ONB-017** [D] Step 13, calories. "Our recommendation" (default) or "Choose my own average daily calories". The recommendation shows its daily average and the week's split.
- **ONB-018** [D][I] The review screen must summarise the plan before starting: goal, phase length, start and end weight, weekly rate, stats, steps, training days and times, meals, and the first mesocycle (name, length, days, deload week). It also shows the weigh-in days and times, and the calorie target marked "Selected" next to "Recommended". Each section has an Edit link back to its step.
- **ONB-019** [D] The review screen must say that exact meal times and amounts can be fine-tuned after starting.
- **ONB-020** [D] Pressing "Start my plan" must show a short loading screen while targets are generated. The plan starts on the chosen start date, by default tomorrow.
- **ONB-021** [M][G] If the user's last diet ended in the past 8 weeks (asked on step 1 as "Have you finished a fat-loss diet recently?"), choosing Fat loss must recommend starting with Maintenance first and explain why. The user can override.

## First-run tour (ONB)

- **ONB-030** [D] After setup, the Today screen must run a skippable coach-mark tour of 7 steps: days and calories (week strip), the day's totals, a meal card, a workout card, the weigh-in row, the menu, and the bottom tabs.
- **ONB-031** [D] Before the plan's start date, Today must show the upcoming days with a banner "Your plan starts Thu, Apr 23" and let the user plan meals and review workouts in advance.
- **ONB-032** [D] The Progress tab must show an empty state until 4 weigh-ins exist ("Log 4 weigh-ins to see your trend").
- **ONB-033** [D] The first time a meal screen opens with a food in it, a skippable 3-step coach mark must explain editing a food's quantity, locking it, and rebalancing. It is shown once.

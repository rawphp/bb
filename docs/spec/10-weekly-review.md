# 10. Weekly review

RP has a nutrition review in the Diet app and nothing weekly in Hypertrophy. Here there is one review covering the whole week. It is the only place calories change automatically, and the place where diet and training fatigue are judged together.

## Flow (REV)

- **REV-001** [D][I] The weekly review must become available on the user's review day (ONB-016) once the week has at least 2 weigh-ins. A card on Today and Progress says "Weekly review available".
- **REV-002** [D] If the user hasn't done the review within 3 days, the card must stay and the week's targets must repeat until they do. Planned weeks win over repeats: a scheduled diet break, deload at maintenance (PLAN-022) or away week (SCH-060) still applies.
- **REV-003** [D][I] The review must have these steps, each one screen with Back and Next:
  1. **Weigh-ins.** The week's list, with missing ones in orange and an option to add them late.
  2. **Meals.** How many meals were checked in, "Excellent work" when all were, and any unchecked meals listed to check in now (MEAL-045 doesn't apply here).
  3. **Training.** Sessions done, skipped and missed; sets done against planned; underperformance flags; muscles at their volume ceiling; joint-pain flags; and what next week is (normal, last hard week, or deload).
  4. **Progress.** Trend weight against the goal range for the phase, with the week's rate against target.
  5. **Goal.** Keep current goal (recommended), Choose a new goal (opens PH-030), or End the plan.
  6. **Calories.** See REV-010.
  7. **Preview.** Next week's days, each with calories, day type and workout, then Save.
- **REV-004** [D] Step 7 must let the user tap a day to move calories between days before saving (NUT-021).
- **REV-005** [I] In training-only mode, the review must show steps 1, 3 and 4 only.
- **REV-006** [B] The meals step must lay the week out as one row per day, with a mark for each planned meal: a check when it was checked in, or an open circle that checks it in with one tap (meal_check_in). A check-in is not removed from here. The user sees which days fell off at a glance instead of reading a list.

## Calorie choice (REV)

- **REV-010** [D] The calories step must show last week's target, last week's average consumed, and four options:
  - **Take the recommendation** (marked recommended);
  - **Repeat this week**;
  - **Choose my own calories** (average daily calories, within safety limits);
  - **Drop fatigue** ("Take a break. Eating closer to maintenance will drop fatigue and may let you judge your progress better"). Shown during Fat loss only, and hidden when the week just finished or the week ahead is already a diet break.
- **REV-011** [I] Choosing Drop fatigue must make next week a diet break at maintenance calories.
  - If the mesocycle's deload is 1 or 2 weeks away, the app must offer only "Deload now". The deload moves to next week, so the diet break and deload are one week.
  - Otherwise it asks "Make this your deload week too?" Yes moves the deload to next week and is the default when at least 3 training weeks are done. No leaves training as planned.

  When the deload moves, the mesocycle ends a week or more early. The RIR for the remaining training weeks is recomputed from TBL-023 for the new length, and PLAN-020 alignment and the MESO-060 prompt follow the new deload week.
- **REV-012** [I] When next week is already a deload during Fat loss, the calories step must say it is a diet break at maintenance (PLAN-022) and show Repeat and Choose my own as the only other options.
- **REV-013** [D] Each option must show next week's average daily calories and its macro split before the user confirms.

## Recommendation rules (REV)

- **REV-020** [D] Recommendations must use trend weight, not single weigh-ins. Trend weight is defined in WT-010.
- **REV-021** [D][G] Measured expenditure must be estimated weekly once there are 3 eligible weeks of data. Expenditure = average intake − (change in trend weight in kg × 7,700 ÷ days), averaged over the last 3 eligible weeks with the weights in TBL-051. Intake here is checked-in and manually entered food only. Planned meals that were never checked in don't count, whatever SET-003 says.
- **REV-022** [G] A week is eligible for REV-021 only if at least 5 of 7 days were logged, it was not a diet break, deload at maintenance or away week, and it was not the week straight after one, because of the water swing. Ineligible weeks keep the last estimate. REV-025 and SAFE-013 use the same checked-in intake.
- **REV-023** [D][G] The recommendation must be the calorie level that hits the phase's target rate from measured expenditure. Until REV-021 has a valid estimate, it uses the formula expenditure (NUT-002). It changes by no more than 250 kcal per day from this week's target, and not at all when the change would be under 50 kcal. Exception: the week after a diet break goes straight back to the last non-break target, with no cap and no REV-026 hold.
- **REV-024** [D] When the week's rate is within the target band (TBL-050), the recommendation must be to keep calories as they are.
- **REV-025** [D] When adherence was poor (fewer than 5 logged days, or average consumed more than 10% off target), the recommendation must be "Repeat this week" with the reason shown. The user hits targets first, then the app adjusts.
- **REV-026** [D][G] The app must hold calories instead of cutting when the trend rose because of likely water. That is when the week included a diet break, a deload, a higher-carb day shift, or a jump in daily weight over 1% that reversed within 3 days. It shows "Water weight is up. Holding your target this week."
- **REV-027** [D][G] Stall detection: when the trend moves less than 25% of the target rate for 2 reviews running during Fat loss, the review must say "Your progress has stalled" and offer:
  - cut calories by the recommended amount;
  - add 1,000 to 2,000 steps a day. The recommendation never goes above 10,000, though the user may choose more (SAFE-015 warns above 12,000);
  - hold for another week;
  - take a diet break.

  The app must recommend one change at a time, starting with calories unless the fat floor is close, in which case steps.
- **REV-028** [D] During Muscle gain, a stall must recommend more calories. Gaining faster than the band must recommend fewer.
- **REV-029** [D][G] During Maintenance, the target must be a weight corridor: trend weight within ±1% of the trend weight at the start of the phase (TBL-050). Inside the corridor, keep calories. Outside it, recommend a change that steers back toward the middle, using REV-023's caps. A small gain or loss target (PH-004) moves the corridor's centre by that rate each week.
- **REV-030** [D] All recommendations must respect SAFE-008 to SAFE-012.
- **REV-031** [D] A recommendation must never be applied without the user choosing it.
- **REV-032** [M][G] The first week of every phase must be left out of the rate calculation, since RP says not to read the trend until a week in. The first review of a phase must hold calories unless adherence was poor.

## Fatigue (REV)

- **REV-040** [I][G] The review must judge fatigue from both halves. Training fatigue counts:
  - underperformance flags (PROG-050);
  - "Too much" and "I'm still sore!" answers;
  - skipped sessions.

  Diet fatigue counts:
  - weeks since the last diet break;
  - stalls;
  - adherence falling.

  The thresholds for "high" are in TBL-051. Skipped sessions in away weeks (SCH-061) don't count.
- **REV-041** [I][G] During Fat loss, when training fatigue is high (TBL-051), the review must recommend Drop fatigue over cutting volume, saying "Your lifts are dropping. It might be the diet, not the training."
- **REV-042** [I][G] During Fat loss, when 6 or more weeks have passed since the last diet break or deload, the review must suggest Drop fatigue even without other signs.
- **REV-043** [I] If training fatigue stays high after a diet break and deload, the review must recommend ending the fat-loss phase and moving to Maintenance.
- **REV-044** [I] During Muscle gain, high training fatigue must not trigger a diet change. It is left to training rules (PROG-052).
- **REV-045** [I][G] During Muscle gain, when the week's rate is on target (TBL-050) and training fatigue is high (TBL-051), the review must show the recovery step (PROG-052) with "Your weight is on track. The lifts are a training problem, not a food one."
- **REV-046** [I][G] During Fat loss, when the measured rate is faster than the target band (TBL-050) and training fatigue is high, the recommendation must raise calories to the target rate in one step, without REV-023's 250 kcal cap. It applies only when that level is above the week's target; otherwise the week follows the other rules. It says "You're losing faster than planned and your lifts are paying for it." When REV-041 also applies, Drop fatigue stays the recommendation. The week after the break returns to this corrected target instead of the last non-break one (REV-023), but only when REV-046 decided the week before the break.

## Steps (REV)

- **REV-050** [I][G] When the week has step data (WT-020, INT-001), the review must compare actual steps with the step plan. If the week's average differs by more than 2,000 a day, the review must offer to update the step plan, which changes future expenditure.

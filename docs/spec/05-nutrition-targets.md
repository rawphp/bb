# 05. Nutrition targets

RP doesn't publish its formulas. The equations here follow what RP says it uses (Mifflin-St Jeor, steps, workout duration and intensity), *Renaissance Diet 2.0*, and Tom's April 2026 RP Diet screenshots. The tuning numbers are best guesses, collected in [14-defaults-and-tables.md](14-defaults-and-tables.md).

## Energy (NUT)

- **NUT-001** [D] Basal metabolic rate must use Mifflin-St Jeor from sex, age, height and weight.
- **NUT-002** [D][G] Daily expenditure must be BMR × activity factor from the day's planned steps (TBL-030), plus the day's workout energy.
- **NUT-003** [I][G] Workout energy must be session length (hours) × bodyweight (kg) × an intensity factor: 3 for light, 5 for moderate, 6 for hard.
- **NUT-004** [I] A session's intensity must come from its planned working sets (TBL-031), not from user input. Differs from RP: RP Diet asks the user to rate each workout light, moderate or hard.
- **NUT-005** [I] "Other activity" workouts (SCH-032) must use the intensity the user picked.
- **NUT-006** [D] After 3 weeks of data, expenditure must switch from the formula to the measured estimate in REV-021.
- **NUT-007** [D] Daily calorie target must be expenditure plus or minus the energy needed for the phase's weekly rate (7,700 kcal per kg, spread over 7 days).

## Macros (NUT)

- **NUT-010** [M][G] Protein must be 2.2 g per kg (1 g per lb) of lean-adjusted bodyweight. That is bodyweight when body fat is at or below 20% (men) or 30% (women), and lean mass × 1.25 above that. Protein stays the same across phases.
- **NUT-011** [M][G] During the first 2 weeks of a muscle-gain phase that follows fat loss, protein must rise to 1.5x NUT-010, capped at 3.3 g per kg, to help with hunger.
- **NUT-012** [M][G] Fat must default to 25% of the week's average daily calories, as the same grams every day, with a floor of the larger of 15% of calories (SAFE-009) and 0.66 g per kg of lean mass (0.3 g per lb).
- **NUT-013** [M] Carbohydrate must take the remaining calories.
- **NUT-014** [M][I] Carbs must scale with training through one mechanism only. Each day's calories come from that day's expenditure (NUT-002, NUT-003) plus or minus the phase's daily energy change (NUT-007). Protein and fat are the same grams every day of the week, so the whole difference between days lands on carbs. TBL-032 gives sanity bounds and is not a second split.
- **NUT-015** [D] Calorie cuts must come out of fat first, down to the fat floor, then carbs. Carb cuts must take the same grams from every day, which keeps the training-day lead over rest days. This follows RP's "cut fats first, hold carbs high as long as possible for training".
- **NUT-016** [D] Calorie increases must go to carbs first until training-day carbs hit 3 g per lb of lean mass, then split between carbs and fat.
- **NUT-017** [D] Fiber must count toward carbs as listed on labels. The app must use total carbs, not net carbs.
- **NUT-018** [D] Every macro in every food must count toward targets, including the incidental fat in a protein source and the protein in bread.

## Weekly calorie goal (NUT)

- **NUT-020** [D] The calorie target must be weekly. The daily targets add up to the week.
- **NUT-021** [D] Users must be able to move calories between days (for example less on weekdays, more on Saturday) while the weekly total holds. The week strip and Adjust sheet (DAY-020) show the effect.
- **NUT-022** [G] No day may be moved below 80% or above 125% of the week's daily average. Protein never moves.
- **NUT-023** [I] Training days must get more calories than rest days by default. Diet-break weeks and every deload week (PLAN-022, PLAN-024) set the daily energy change to zero, so each day is at its own expenditure. Deload sessions count as Light days (TBL-031).
- **NUT-024** [D] Days already completed must be locked out of moves.

## Per-meal targets (NUT)

- **NUT-030** [D] Each meal must have its own calorie, protein, fat and carb target. The meal targets add up to the day.
- **NUT-031** [M][I] On training days, carbs must be split by meal position using *Renaissance Diet 2.0* Table 10.8 (TBL-033). The pre-workout and post-workout meals get the biggest shares and the meals furthest from training get the rest. On rest days, carbs are split evenly.
- **NUT-032** [M][G] Fat must be lowest in the pre-workout and post-workout meals (minimum 5 g) and moved into the meals furthest from training.
- **NUT-033** [M][G] Protein must be split evenly, with the bedtime meal (last of the day) getting up to 15% more.
- **NUT-034** [M][G] Meal targets must round to friendly numbers: carbs, protein and fat to 5 g, calories to 5 kcal. "50, 50, 25, 25 is easier than 61, 48, 22, 19." The rounding remainder goes to the largest meal for that macro, so the meals still add up to the day (NUT-030).
- **NUT-035** [D] Users must be able to edit a meal's targets by hand ("Edit macros/time"). The other unchecked meals absorb the difference.
- **NUT-036** [D] SAFE-010 (5 g fat and 5 g carbs per meal) must hold after every redistribution.
- **NUT-037** [M][G] Users must be able to choose carb placement: training-centred (default) or evening-weighted, for people who can't sleep hungry on a cut. Evening-weighted moves up to 10 percentage points of carbs from the pre-workout share to the bedtime meal.

Reference from Tom's RP Diet captures. It is a direction check for NUT-031 and NUT-032, not an exact one, since RP's numbers don't come from our tables. Acceptance: the post-workout meal must carry more carbs than any other meal, and the pre- and post-workout meals must have the lowest fat. RP gives the post-workout meal a bigger share than TBL-033 does (about 120 g here). The user rated the session "moderate" in RP; under TBL-031 a 30-minute session would be Light. Fat-loss day, 6 meals, 30-minute workout at 6:30 AM:

| Meal | Time | kcal | P | F | C |
|---|---|---|---|---|---|
| 1 (pre) | 5:00 AM | 430 | 40 | 10 | 45 |
| Workout | 6:30 AM | | | | |
| 2 (post) | 7:00 AM | 710 | 35 | 10 | 120 |

The rest day in the same week was 2,550 kcal with 45 g carbs per meal. The training day was 2,770 kcal.

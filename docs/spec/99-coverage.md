# 99. Coverage matrix

Status: draft 2, 2026-10-09.

Every researched feature of RP Diet Coach (v1.57), RP Hypertrophy (v1.7) and RP's combined methodology, mapped to the requirement IDs that state the behaviour. A feature counts as covered only when a requirement says it; "partial" means part is stated and the rest is a gap.

Source key: "Diet n", "Hyp n" and "Int n" are section numbers in `~/EA/research/rp-apps/rp-diet.md`, `rp-hypertrophy.md` and `rp-integration.md`. File names are screenshots listed in the `INDEX.md` files under `~/EA/research/rp-apps/screenshots/`.

| Group | Features | Covered | Gaps |
|---|---|---|---|
| RP Diet | 274 | 274 | 0 |
| RP Hypertrophy | 146 | 146 | 0 |
| RP methodology | 89 | 89 | 0 |
| Total | 509 | 509 | 0 |

Deliberately not carried or out of scope: 28 further items (listed below, not counted above).

## RP Diet


### Product, platforms and account

| Feature | Source | Requirement IDs |
|---|---|---|
| Coach not tracker: builds meal-by-meal targets and food amounts, adjusts weekly | Diet 1 | NUT-030, MEAL-002, REV-023 |
| Needs a gram food scale, bathroom scale and training access (tools article) | Diet 1 | LRN-005 |
| Platform: iPhone, iOS 16 and later | Diet 1 | PLT-001 |
| Platform: Android phones | Diet 1 | PLT-001 |
| Platform: runs on Apple-silicon Macs | Diet 1 | PLT-004 |
| Platform: runs on visionOS | Diet 1 | PLT-004 |
| Cloud Sync row in More menu with status ("Synced a few seconds ago") | Diet 1, more-menu-shopping-cloud-sync.png | ACC-003, SET-020 |
| Sign in with Apple, Google or email | Diet 1 | ACC-001 |
| Login must match the subscription email | Diet 1 | SUB-011 |
| Dark mode on all screens | Diet 1 (1.57) | PLT-005 |

### Setup wizard

| Feature | Source | Requirement IDs |
|---|---|---|
| Setup resumable after quitting (1.56.9) | Diet 2 | ONB-002 |
| Step: diet goal (Fat loss, Muscle gain, Maintenance) | Diet 2, setup-1-diet-goal-choice.png | ONB-003, PH-001 |
| Help text: lose fat and gain muscle at once means pick Fat loss, no recomp mode | Diet 3 | ONB-003 |
| Step: about you (name, sex male or female, weight, height, age) | Diet 2, setup-2-profile-stats.png | ONB-004 |
| Step: weight-loss drug question (semaglutide, tirzepatide, retatrutide) | Diet 2, setup-3-weight-loss-drugs-question.png | ONB-006, SAFE-013 |
| Step: body fat by photo bucket, male and female sets | Diet 2, setup-4-body-fat-pictures.png, setup-body-fat-picker-male.png | ONB-007, TBL-001 |
| Body-fat help: accuracy not critical, do not use scale readings | Diet 2 | ONB-007 |
| Step: rate plan Highest success (default), Slow & steady, Custom | Diet 2, setup-5-fat-loss-rate-highest-success.png, phase-fat-loss-custom.jpg | ONB-008, TBL-010 |
| Start date defaults to tomorrow; end date; target weight; changing one recalculates the others | Diet 2 | ONB-008 |
| Fat-loss length 2 to 16 weeks in the UI | Diet 2, 3 | ONB-009, TBL-010, SAFE-006 |
| Step: step plan as a range per day, weekday and weekend presets | Diet 2, setup-8-step-plan-workout-schedule.png | ONB-013 |
| Step: workout schedule (days, start time, duration) | Diet 2 | ONB-011 |
| Workout intensity chosen by the user: light, moderate or hard | Diet 2, setup-9-workout-light-moderate-hard.png | NUT-004, TBL-031 |
| Step: meal schedule (days, first meal, last meal, 2 to 6 meals) | Diet 2, setup-11-edit-meal-schedule-first-last-meal-count.png | ONB-014 |
| Optional busy times in setup | Diet 2 | ONB-014 |
| Same eating window for every day or set per day | Diet 2, setup-review-plan-top.png | ONB-014 |
| Step: weigh-in schedule, up to daily, days and time | Diet 2, setup-12-weigh-in-schedule.png | ONB-015, WT-001 |
| Weigh-in sheet: Mon to Sun day toggles | setup-weighin-add-schedule.png | ONB-015, WT-001 |
| Weigh-in sheet: Select all days | setup-weighin-add-schedule.png | WT-007 |
| Weigh-in sheet: Delete weigh-in schedule | setup-weighin-add-schedule.png | WT-007 |
| Weigh-in time note: sets the notification time only | setup-weighin-add-schedule.png | ONB-015 |
| Warning "Too few weigh-ins to continue", at least 2 days, Next disabled | setup-weighin-too-few-warning.png | ONB-015 |
| Hint to weigh in at least 4 times a week | setup-weighin-two-days-hint.png | ONB-015 |
| Advice: weigh daily, first thing in the morning | setup-weighin-add-schedule.png, Diet 4 | WT-005, ONB-015 |
| Step: calorie target, "RP recommendation" with recommended badge and daily average | Diet 2, setup-13-calorie-target-rp-recommendation.png, setup-calorie-target-rp-recommendation.png | ONB-017 |
| Calorie target: customise own average daily calories, stepper sheet "Choose your target", Set target | setup-calorie-custom-sheet.png | ONB-017 |
| Link "Learn more about diet goals and safety" | setup-calorie-target-rp-recommendation.png | LRN-003 |
| Review screen: goal, length, start and end weight, change and weekly rate | Diet 2, setup-14-review-plan.png, setup-review-plan-top.png | ONB-018 |
| Review screen: details (sex, age, height, weight, body-fat range) | setup-review-plan-top.png | ONB-018 |
| Review screen: step count (weekday and weekend), workout schedule, meals summary | setup-review-plan-top.png | ONB-018 |
| Review screen: weigh-in schedule and calorie target (selected against recommended) | setup-review-plan-bottom.png | ONB-018 |
| Review screen: Edit link on every section | setup-review-plan-bottom.png | ONB-018 |
| Review screen note: meal timing and quantities can be fine-tuned after starting | setup-review-plan-top.png | ONB-019 |
| Start my diet button; diet usually starts the next day | Diet 2 | ONB-020 |
| Loading screen "Getting everything ready for you, determining daily macros" | setup-getting-ready-splash.png | ONB-020 |
| Setup step counter ("Step 10 of 11") | setup-weighin-add-schedule.png | ONB-002, UI-007 |

### Health screening and safety rails

| Feature | Source | Requirement IDs |
|---|---|---|
| Do-not-use list: under 18 or over 65 | Diet 2 | SAFE-001 |
| Do-not-use list: height under 54 in or over 84 in | Diet 2 | SAFE-001 |
| Do-not-use list: weight over 350 lb | Diet 2 | SAFE-001 |
| Do-not-use list: BMI under 17 | Diet 2 | SAFE-001 |
| Do-not-use list: transgender on hormone therapy | Diet 2 | SAFE-001 |
| Do-not-use list: breastfeeding or pregnant | Diet 2 | SAFE-001 |
| Do-not-use list: heart disease, thyroid cancer, PCOS, hyper- or hypothyroidism | Diet 2 | SAFE-001 |
| Do-not-use list: weight-loss surgery in past 5 years, gallbladder disease | Diet 2 | SAFE-001 |
| Do-not-use list: autoimmune disease, type 1 or type 2 diabetes, eating-disorder history | Diet 2 | SAFE-001 |
| No fat-loss diet when trying to conceive or in fertility treatment | Diet 2 | SAFE-001 |
| Medical clearance required before starting | Diet 2 | SAFE-002 |
| Medical diets and metabolic conditions: consult a physician, not supported | Diet 8 | SAFE-014 |
| Rate hard cap 1% bodyweight per week | Diet 3 | SAFE-005 |
| Fat-loss phase max 16 weeks | Diet 3 | SAFE-006 |
| Muscle-gain phase max 16 weeks | Diet 3 | SAFE-007 |
| Calorie decreases come from fat and carbs, protein never cut | Diet 3, 4 | SAFE-008, NUT-015 |
| Fat floor 15% of calories | Diet 3 | SAFE-009 |
| Every meal keeps at least 5 g fat and 5 g carbs | Diet 3 | SAFE-010 |
| BMI floor 17 stops further loss | Diet 3, 15.3 | SAFE-011 |
| Safety stop: app recommends raising calories when floors are hit | Diet 15.3 | SAFE-012 |
| GLP-1 answer changes plan logic | Diet 2, 16 | SAFE-013 |
| Nutrient warning (contents unknown in research) | Diet 10, 16 | SAFE-018 |

### Phases and sequencing

| Feature | Source | Requirement IDs |
|---|---|---|
| Three diet types | Diet 3 | PH-001 |
| Fat-loss recommended length 6 to 12 weeks, under 6 is a mini-cut, over 12 not advised | Diet 3 | TBL-010, PH-002 |
| Muscle-gain rate 0.125 to 0.5% per week, advanced low, beginner near 0.5% | Diet 3, phase-muscle-gain-setup.jpg | TBL-010 |
| Muscle-gain length 8 to 16 weeks recommended | Diet 3 | TBL-010 |
| Maintenance target weight defaults to current, small gain or loss allowed | Diet 3, phase-maintenance-setup-target-weight.jpg | PH-004, TBL-010 |
| Maintenance length about 2/3 of previous diet, or indefinite | Diet 3 | PH-031, PH-005 |
| Maintenance strongly recommended after any fat-loss or gain phase | Diet 3 | PH-031, PH-033 |
| Off a diet: start with maintenance | Diet 3, 15.3 | ONB-021, PH-031 |
| No fat loss straight into fat loss | Diet 3 | PH-034 |
| New diet takes calories from previous phase's measured results (1.56.8) | Diet 3, 15.3 | PH-035 |
| Fat loss, maintenance, muscle gain, maintenance cycle | Diet 3 | PH-031, PH-033, PH-034 |
| Adjust Diet Length (More menu) extend or shorten | Diet 3, more-menu-custom-foods-shopping-weighins-adjust-length.png | PH-006, SET-020 |
| End Current Diet (red row in More menu) | more-menu-shopping-cloud-sync.png | PH-007, SET-020 |
| End-of-diet review offers Keep goal, Choose new goal, End diet | Diet 3, 15.3 | PH-041 |
| Check muscle-gain length leaves time before a deadline (wedding example) | Diet 15.3 | PH-042 |

### Macro and calorie logic

| Feature | Source | Requirement IDs |
|---|---|---|
| Daily calories from TDEE plus or minus goal rate | Diet 4 | NUT-007 |
| TDEE from BMR (Mifflin-St Jeor), workout term, activity factor, age, height, weight | Diet 2, safety-tdee-formula.png | NUT-001, NUT-002, NUT-003 |
| Protein held high | Diet 4 | NUT-010 |
| Weekly calorie goal, calories movable between days | Diet 4 | NUT-020, NUT-021 |
| Week strip shows each day's kcal | Diet 4, 5 | SCH-020 |
| Targets differ by day from steps and workouts | Diet 4 | NUT-002, NUT-023 |
| Workout and meal times drive per-meal timing and macros | Diet 2 | SCH-042, NUT-031 |
| Carbs concentrated around workouts, fats far from them (unofficial, unconfirmed) | Diet 2, 5 | NUT-031, NUT-032 |
| Incidental macros from every food counted (v1.5) | Diet 6 | NUT-018 |
| Net carbs counted (unofficial) | Diet 6 | NUT-017 |

### Weekly review

| Feature | Source | Requirement IDs |
|---|---|---|
| Review available once the week's weigh-ins are in; card on Progress and Schedule | Diet 4, progress-tab-trend-weight-weekly-review-available.png | REV-001 |
| Step 1: review weigh-ins, orange for missing | weekly-review-1-review-weigh-ins.png | REV-003 |
| Step 2: check in meals, "Excellent work" when all checked, see details | weekly-review-2-check-in-meals.png | REV-003 |
| Step 3: review progress, trend weight against goal range, green bars | weekly-review-3-review-progress-range.png | REV-003 |
| Step 4: review goal, Keep current goal (recommended), Choose a new goal, End my diet | weekly-review-4-review-goal.png | REV-003 |
| Step 5: choose calories, last week's target against consumed average | weekly-review-5-choose-calories-recommendation.png | REV-010 |
| Calorie option: Take the recommendation (RP recommended) | weekly-review-5-choose-calories-recommendation.png | REV-010 |
| Calorie option: Repeat this week | weekly-review-5b-repeat-this-week-option.png | REV-010 |
| Calorie option: Choose my own calories | Diet 4 | REV-010 |
| Calorie option: Drop fatigue, with its explanatory text | store-weekly-checkin-choose-calories-drop-fatigue.png | REV-010, REV-011 |
| Maintenance break option (1.56.5, UI not seen) | Diet 4 | REV-011 |
| Step 6: preview and confirm next week's days, Save | weekly-review-6-preview-and-confirm.png | REV-003, REV-004 |
| Recommendations use trend weight, not single weigh-ins | Diet 4 | REV-020 |
| Steadier, less reactive to water weight, hold your target when water weight is up (1.55.1) | Diet 1, 4 | REV-026 |
| Stall detection with suggested options (1.55.1) | Diet 1, 4 | REV-027 |
| Fix for wrongful calorie-cut suggestions (1.56.12) | Diet 4 | REV-023, REV-025 |
| Single weigh-in bounce education ("my weight went up?") | Diet 4 | WT-005, LRN-001 |
| Weekly review shown at the end date with Keep goal, Choose new goal, End plan | Diet 3 | PH-041 |

### Schedule

| Feature | Source | Requirement IDs |
|---|---|---|
| Schedule shows meals with time and per-meal kcal, protein, fat, carbs, workouts and weigh-in | Diet 5, schedule-overview-day-balance.png | SCH-001, SCH-002 |
| Foods shown on meal cards (1.57) | Diet 1 | SCH-002 |
| Eating windows and busy periods, night shift and fasting patterns | Diet 5 | SCH-045, SCH-033, ONB-014 |
| Busy periods shown as blocks | Diet 5 | SCH-005 |
| Schedule menu: Add meal | schedule-menu-add-meal-workout-busy-copy-optimize.png | SCH-030, SCH-031 |
| Schedule menu: Add workout | schedule-menu-add-copy-edit.png | SCH-030, SCH-032 |
| Schedule menu: Add busy period | schedule-menu-add-copy-edit.png | SCH-030, SCH-033 |
| Schedule menu: Copy day | schedule-menu-add-copy-edit.png | SCH-030, SCH-034 |
| Schedule menu: Copy week | Diet 6 | SCH-030, SCH-035 |
| Schedule menu: Optimize | schedule-menu-add-meal-workout-busy-copy-optimize.png | SCH-030, SCH-036 |
| Schedule menu: Edit schedule | schedule-menu-add-copy-edit.png | SCH-030, SCH-037 |
| Week strip, swipe to change week, tap to change day | schedule-week-switcher.png, tour-days-and-calories.png | SCH-020 |
| Week strip shows "-" for days before the start | tour-days-and-calories.png | SCH-021 |
| Green dot on completed days | Diet 10 | SCH-021, ANA-011 |
| Condensed view toggle (stacked-view icon) | schedule-condensed-view.png, schedule-pre-start-rest-day.png | SCH-007 |
| Day stats bar with Adjust button (1.57) | Diet 7 | SCH-006, DAY-020 |
| Day target bars for kcal, protein, fat, carbs | tour-target-macros.png | SCH-006, MEAL-001 |
| Step count target row on the day (for example 0 to 8k) | tour-target-macros.png, schedule-pre-start-rest-day.png | SCH-011 |
| Header "WEEK 1" label | schedule-pre-start-rest-day.png | SCH-022 |
| Calendar icon to jump dates | meal-chicken-under-target-unchecked.png | SCH-023 |
| Banner "Your diet starts in 1 day", plan ahead before start | schedule-pre-start-rest-day.png, onboarding-3-consistency-is-key.png | ONB-031 |
| Plan meals before eating them (guidance) | onboarding-2-plan-meals-before-eating.png | SCH-008 |
| Edit meal time and macros by hand | tour-edit-meal-swipe.png | SCH-044, NUT-035 |

### First-run cards and tour

| Feature | Source | Requirement IDs |
|---|---|---|
| Intro card 1 "You can do it": consistency over perfection, within 10% on calories and protein | onboarding-1-you-can-do-it.png | ONB-001, DAY-021 |
| Intro card 2 "Plan your meals before you eat them" | onboarding-2-plan-meals-before-eating.png | ONB-001, SCH-008 |
| Intro card 3 "Consistency is key": weigh in 4 times a week, estimates are fine | onboarding-3-consistency-is-key.png | ONB-001, ONB-015 |
| Tour: days and calories (week strip) | tour-days-and-calories.png | ONB-030 |
| Tour: target macros | tour-target-macros.png | ONB-030 |
| Tour: meals, tap to add or edit foods, long press for shortcuts | tour-meals-tap-longpress.png | ONB-030, SCH-010 |
| Tour: swipe right to check in at shown macros, manual button for own macros | tour-check-in-swipe.png | ONB-030, MEAL-041 |
| Tour: swipe left to delete meal or edit macros or time | tour-edit-meal-swipe.png | SCH-010 |
| Tour: Day status, add the burrito and see the effect, protein bar example, wand reallocates | tour-day-status.png | ONB-030, DAY-002, DAY-010 |
| Meal-screen coach mark: edit food quantity | tutorial-edit-food-quantity.png | ONB-033 |
| Meal-screen coach mark: lock food quantity | tutorial-lock-food-quantity.png | ONB-033 |
| Meal-screen coach mark: rebalance | tutorial-rebalance.png | ONB-033 |
| Help popup "Need help building your meals?" with How to think about it, How to do it | help-building-meals-popup.png | UI-009, LRN-003 |

### Food database, pantry and search

| Feature | Source | Requirement IDs |
|---|---|---|
| Food database of 750,000+ foods including restaurant and packaged | Diet 6 | FOOD-001 |
| Verified foods badge (red shield) | Diet 6, pantry-favorites-search-filters.png | FOOD-002 |
| Pantry tabs: Curated, Recents, Favorites, Custom | Diet 6, pantry-browse-tabs-rp-foods-by-macro.png | FOOD-020 |
| Recipes and Saved Meals appear as filters in the builder | Diet 6 | FOOD-020 |
| Curated groups: Protein, Veggies, Carbs, Fat, 5 shown with "Show 25 more" | Diet 6 | FOOD-003, FOOD-021 |
| Favorite by swipe or by tapping the food image | Diet 6 | FOOD-022 |
| Search shows local results first, then "More results" | pantry-search-recents-more-results.png | FOOD-023 |
| "Did you mean" typo correction | Diet 6 | FOOD-024 |
| Source filters: Recents, Favorites, Curated, Custom, Restaurants, Packaged | pantry-source-filter-sheet.png, pantry-favorites-search-filters.png | FOOD-025 |
| Curated subgroup filter (Protein recommended, use sparingly, before bed; Carbs; Fat) | pantry-rp-foods-subgroup-filter.png | FOOD-003, FOOD-026 |
| Basket with "Adding to meal" and "Already in meal" | build-meal-basket-confirm.gif | FOOD-028 |
| "Can't find it? Create a custom food" prompt | food-search-filters-create-custom-prompt.png, pantry-create-custom-food-prompt.png | FOOD-062 |
| Food amount unit chips g and oz | pantry-search-keyboard-oz.png | FOOD-009 |
| Household units beside grams (for example 2.6 slices) | Diet 6, tutorial-edit-food-quantity.png | FOOD-004, FOOD-005 |
| Gram weights for cup and container foods (1.53.5) | Diet 6 | FOOD-005 |
| Pantry search speed | Diet 6 | FOOD-029 |
| Diet filters: dairy free, gluten free, grain free, low FODMAP, ovo-lacto vegetarian, paleo, vegan | Diet 8 | FOOD-027 |
| Diet filters show only foods matching all selected | Diet 8 | FOOD-027 |
| Cooking oils measured and counted | Diet 6 | FOOD-007 |
| Raw or cooked toggle per food, both hit the same macros, advice when to use which | Diet 6, build-meal-edit-amounts-raw-cooked.gif | FOOD-006 |
| Drinks in database | Diet 6 | FOOD-008 |
| Restaurant items searchable | Diet 8, 9 | FOOD-080 |
| Alcohol advice (none while dieting, at most 1 to 2 drinks a week) | Diet 8 | FOOD-081 |
| Coffee and caffeine are fine | Diet 8 | FOOD-081 |

### Scanning and custom foods

| Feature | Source | Requirement IDs |
|---|---|---|
| Camera button with three modes | store-barcode-label-scanner.png | FOOD-040 |
| Barcode scan | Diet 6 | FOOD-041 |
| Nutrition-label scan with AI | Diet 6 | FOOD-042 |
| Photo scan estimates each food and portion into an editable ingredient list | ai-food-scanner-editable-ingredients.jpg | FOOD-043 |
| Photo mode accepts a typed description | Diet 6 | FOOD-044 |
| Portion-size card scales the whole scanned meal | Diet 6 | FOOD-045 |
| Scanned meal saved as a food combo (now Saved Meal) | Diet 6 | FOOD-046 |
| Custom food fields: name, brand, UPC, serving size, fat, carbs, fiber, protein | custom-food-nutrition-fields.png | FOOD-060 |
| Custom food extra units (cups, spoons, weight per serving) | Diet 6 | FOOD-060 |
| Custom foods manager under More | custom-foods-list.png | FOOD-061 |

### Meal builder

| Feature | Source | Requirement IDs |
|---|---|---|
| Plan-first model, optimizer fills to meal target not one standard serving | Diet 6 | MEAL-002 |
| Meal screen targets as bars (kcal, P, F, C) with consumed against target | meal-empty-assumes-target-macros-apr28.png | MEAL-001 |
| Meal "Targets met" state when within tolerance | copy-foods-thu-source-meal-targets.png | MEAL-003 |
| Meal status pills for kcal and protein ("on track", "315 under") | meal-chicken-under-target-unchecked.png | MEAL-011 |
| Edit amount with minus and plus or by typing | tutorial-edit-food-quantity.png | MEAL-004 |
| Lock icon keeps amount through copy, save and rebalance | tutorial-lock-food-quantity.png, meal-three-foods-salsa-locked.png | MEAL-005 |
| Rebalance (scales icon) | tutorial-rebalance.png | MEAL-006 |
| Swipe a food to delete | build-meal-lock-icon-swipe-delete.gif | MEAL-008 |
| Favorite star on a food row | meal-three-foods-salsa-locked.png | FOOD-022 |
| Over-target macros outlined but still "on track" | tutorial-lock-food-quantity.png | MEAL-043 |
| Empty meal banner "Because this meal has no foods in it, the app assumes you are eating this meal at target macros" and "Your meal is empty" state | meal-empty-assumes-target-macros-apr28.png | MEAL-046 |
| Barcode button and Add foods button at the bottom of an empty meal | meal-empty-assumes-target-macros-apr28.png | FOOD-040, UI-021 |
| Check-in state icon on meal title (red x when not checked in) | meal-chicken-under-target-unchecked.png | MEAL-047 |
| Meal menu: Edit macros/time | meal-three-dot-menu.png | MEAL-009 |
| Meal menu: Load saved meal (food combo) | meal-three-dot-menu.png | MEAL-009, MEAL-070 |
| Meal menu: Save as saved meal | meal-three-dot-menu.png | MEAL-009, MEAL-070 |
| Meal menu: Copy foods | meal-three-dot-menu.png | MEAL-009, MEAL-060 |
| Meal menu: Remove foods | meal-three-dot-menu.png | MEAL-009 |
| Meal menu: Check in meal | meal-three-dot-menu.png | MEAL-009, MEAL-040 |
| Meal menu: Check in meal at manual macros | meal-three-dot-menu.png | MEAL-009 |
| Meal menu: Remove check-in | meal-three-dot-menu.png | MEAL-009, MEAL-044 |
| Meal menu: Still planning foods | meal-three-dot-menu.png | MEAL-009, DAY-004 |
| Meal menu: Delete meal | meal-three-dot-menu.png | MEAL-009 |
| Meal menu: items needing foods greyed on an empty meal | meal-three-dot-menu.png | MEAL-009 |
| Copy foods sheet: Cancel, advanced gear, Save | copy-foods-thu-source-meal-targets.png | MEAL-060, UI-005 |
| Copy foods: destination day from week strip | copy-foods-thu-source-meal-targets.png | MEAL-060 |
| Copy foods: source meal summary with targets met | copy-foods-thu-source-meal-targets.png | MEAL-060 |
| Copy foods: destination meal radio list with targets and "No foods" | copy-foods-thu-source-meal-targets.png | MEAL-060 |
| Copy foods: workout blocks shown between destination meals | copy-foods-fri-workout-block-targets.png | MEAL-060 |
| Copy foods advanced: Update destination target macros (on by default) | copy-foods-advanced-options.png | MEAL-060 |
| Copy foods advanced: Update destination meal times (off), shakes keep their times | copy-foods-advanced-options.png | MEAL-060 |
| Copy foods advanced: Copy source meal locked state (off) | copy-foods-advanced-options.png | MEAL-060 |
| Copy Day and Copy Week | Diet 6 | SCH-034, SCH-035 |
| Saved Meals: locked foods keep amounts, others re-fit (1.56.6) | Diet 6 | MEAL-070, MEAL-071 |

### Check-ins, Day Status, redistribution

| Feature | Source | Requirement IDs |
|---|---|---|
| Check in by tapping the box on the schedule card | meal-checkin-tap-checkbox-schedule.gif | MEAL-040, SCH-009 |
| Check in by swipe right for manual edit of time and macros | meal-checkin-swipe-manual-edit.gif | MEAL-041 |
| Check in from the checkbox in the meal screen | meal-checkin-in-meal-screen.gif | MEAL-040 |
| Check in from the three-dot menu | meal-checkin-three-dot-menu.gif | MEAL-040 |
| Empty meal checked in as given | Diet 7 | MEAL-042 |
| Partially filled meals show remaining macros in Day Status | Diet 7 | DAY-010, DAY-011 |
| Within 5 g per macro counts as on track | Diet 7 | MEAL-043, MEAL-003 |
| Day Status bar "on track" for calories and protein, slight overage fine | day-status-bar-on-track.png | DAY-010, DAY-021 |
| Redistribute button (wand icon) | day-status-redistribute-button.png | DAY-002 |
| Tap which meals receive the redistribution; exclude meals | day-status-redistribute-targets-foods-new.png | DAY-002 |
| Redistribute onto meals that already have foods, not locked ones (1.52) | adjust-meals-redistribute-to-meals-with-food.gif | DAY-003 |
| Orange "Adjust foods" badge | day-status-adjust-foods-warning.png | DAY-003, UI-004 |
| "Still planning foods" and "Done planning foods" states | Diet 7 | DAY-004 |
| Meal with the badge cannot be checked in until resolved, except in the weekly review | Diet 7 | MEAL-045 |
| Adjust sheet shifts calories between meals, projected day calories and protein | adjust-meals-projected-day-calories-protein.jpg | DAY-020 |
| Consumed total includes planned meals by default, toggle for old behaviour | Diet 1 (1.57) | DAY-011, SET-003 |
| Day Balance: save off-plan food macros ("Save Food Macros") | Diet 7 | DAY-001 |
| Frequent check-in notifications, can be turned off | Diet 7 | NOTIF-002, NOTIF-007 |

### Recipes and shopping

| Feature | Source | Requirement IDs |
|---|---|---|
| Recipe build from scratch with servings | Diet 6 | RCP-001 |
| Recipe generation with AI (target macros, meal type, ingredients, complexity, preferences) | recipes-generate-with-ai.gif | RCP-010 |
| Recipe import by URL, ingredients matched to database | Diet 6 | RCP-003 |
| Library of ready-made recipes (166) | recipes-library-166-ready-made.jpg | RCP-020 |
| Recipes apply across days | Diet 6 | RCP-021 |
| Shopping list from planned foods | Diet 9 | SHOP-001 |
| Shopping list breaks recipes into ingredients (1.56.2) | Diet 9 | SHOP-002 |
| Shopping list reachable from the More menu | more-menu-shopping-cloud-sync.png | SHOP-006, SET-020 |

### Progress, weigh-ins, integrations, account

| Feature | Source | Requirement IDs |
|---|---|---|
| Progress tab: trend weight line against goal range | progress-tab-trend-weight-weekly-review-available.png | ANA-001 |
| Progress summary: start, change, goal (with dates) | progress-empty-state.png | ANA-002 |
| Progress empty state until enough weigh-ins | progress-empty-state.png | ONB-032, WT-012 |
| "This week" card with target against consumed and Edit schedule | Diet 10 | ANA-003 |
| Weekly review availability card on Progress | Diet 10 | ANA-004 |
| Step target progress | Diet 10 | ANA-005 |
| Diet History | Diet 10 | ANA-006 |
| Share Progress (More menu) | Diet 10 | ANA-010, SET-020 |
| Weigh-in history list, missing in orange | weighin-history-list.png | WT-004 |
| Weigh-in edit with HealthKit import | weighin-edit-with-healthkit-import.png | WT-003, WT-004 |
| Weigh-in schedule edit | weighin-schedule-edit.png | WT-001 |
| Weigh-in reminder notification | Diet 15.1 | NOTIF-001, WT-002 |
| Apple Health weigh-in sync with times | Diet 11 | INT-001, WT-003 |
| Steps from Apple Health (unconfirmed in RP) | Diet 11 | INT-001 |
| Apple Health settings screen | Diet 11 | INT-004 |
| Android Health Connect (not found in RP) | Diet 11 | INT-002 |
| Weigh-ins on the More menu list | more-menu-shopping-cloud-sync.png | WT-004, SET-020 |
| More menu: Settings and Help rows | more-menu-shopping-cloud-sync.png | SET-008, SET-020 |
| Explore tab (learning content) | more-menu-shopping-cloud-sync.png | LRN-001, NAV-001 |
| Help center "Diet Hub" articles | Diet 13 | LRN-001 |
| Human support by email | Diet 13 | SET-008 |
| Rules-engine coach, no chat coach | Diet 13 | REV-023, REV-031 |
| AI features: photo scan, label scan, recipes, URL import | Diet 13 | FOOD-042, FOOD-043, RCP-010, RCP-003 |

### Pricing

| Feature | Source | Requirement IDs |
|---|---|---|
| Monthly and yearly plans with promo SKUs (sale, referral, gym) | Diet 12 | SUB-002, SUB-009 |
| 30-day refund on website purchases; store refunds through the store | Diet 12 | SUB-005, SUB-006 |
| Switch plan and manage at rp.app/manage | Diet 12 | SUB-007 |
| No free tier | Diet 12 | SUB-003 |

### Walkthrough behaviours

| Feature | Source | Requirement IDs |
|---|---|---|
| Weigh in at the scheduled time on the Schedule row | Diet 15.1 | SCH-004, WT-002 |
| Check off a workout block on the schedule | Diet 15.1 | SCH-003 |
| Update step plan mid-phase | Diet 15.2 | SET-005, REV-050 |
| Edit workout times mid-phase | Diet 15.2 | SCH-037, SCH-050 |
| Hold on a stalled, water-weight week | Diet 15.3 | REV-026, REV-027 |
| Adjust days inside the weekly calorie goal | Diet 15.2 | NUT-021, REV-004 |

## RP Hypertrophy


### Entry, setup and structure

| Feature | Source | Requirement IDs |
|---|---|---|
| Mesocycles tab with (+) offering Template, Copy previous, Build from scratch, Meso Builder | Hyp 1 | MESO-002 |
| Equipment not asked; limited equipment handled by exercise choice | Hyp 1 | EX-011 |
| Home gym help article | Hyp 1 | EX-011, LRN-001 |
| Add a day to a custom meso, default 2 days | Hyp 1 | MESO-045, MESO-020 |
| Help: pick days you can repeat; 2 to 4 consistent days beat 5 or 6 missed | Hyp 1 | ONB-011 |
| Templates from 2-day full body to 6-day delt specialisation | Hyp 1 | MESO-010 |
| Upper/Lower and PPL map to named template families | Hyp 1 | MESO-015 |
| Diet-phase template guidance: full body on a cut, specialise 1 to 2 muscles on a gain, open on maintenance | Hyp 1 | ONB-012, PH-008, PH-010, TBL-012 |
| Weekday labels by default, switch to floating Day 1, Day 2 via Manage Days | Hyp 1 | MESO-005, MESO-045 |
| Days must still be done in order | Hyp 1 | MESO-005 |
| Warm-up ramp guidance: 12 reps at a 30+ weight, 8 at about 20, 4 at about 10RM, then 3 RIR | Hyp 1 | WO-024 |
| Warm-ups are not logged; all logged sets are working sets | Hyp 1, 5 | WO-024, WO-025 |

### Mesocycle structure

| Feature | Source | Requirement IDs |
|---|---|---|
| Length chosen at creation, includes the deload | Hyp 2 | MESO-004 |
| Max 8 weeks total (7 plus deload) | Hyp 2 | MESO-004, TBL-020 |
| Lengthen or shorten mid-meso via calendar icon and plus/minus | Hyp 2 | MESO-042 |
| Length guidance by level (beginner up to 8, intermediate 4 to 7, advanced 3 to 6 accumulation) | Hyp 2 | TBL-020 |
| Deload is the last week and automatic | Hyp 2 | PROG-040, MESO-004 |
| End meso early from the top-right menu, warning for intermediate and advanced | Hyp 2 | MESO-043 |
| Deload removes traps and forearms | Hyp 2 | PROG-042 |
| Push the 0 RIR week before the deload | Hyp 2 | PROG-043 |
| Deload reductions (unpublished by RP; halve sets and reps, then halve weight, from integration report) | Hyp 2, Int 2 | PROG-041 |
| Week 1 at 3 RIR, falling to 0 in the last accumulation week | Hyp 2 | PROG-030, TBL-023 |
| RIR schedule cannot be changed in the app | Hyp 2 | PROG-031 |
| Priority Emphasize (3 bars) adds volume when recovered, toward MRV | Hyp 2 | MESO-030, MESO-031 |
| Priority Grow (2 bars) adds volume only when needed, near MEV | Hyp 2 | MESO-030, MESO-031 |
| Priority Maintain (1 bar) stays at MV | Hyp 2 | MESO-030, MESO-031, PROG-016 |
| Change a muscle's priority mid-meso via workout menu "Progression rates" | Hyp 2 | MESO-032 |
| Template library with filters: days per week, sex, whole-body or emphasis | templates-library-100plus.png | MESO-011 |
| Templates include named pro and athlete plans | template-detail-regan-grimes-ifbb-template.png | MESO-010 |
| Template library size (45+ or 100+) | templates-library-100plus.png, play-templates-45plus-older-listing.png | MESO-010 |
| Template detail: per-day exercise list, muscle priorities | template-detail-dr-mike-favorite-muscle-priorities.png | MESO-012 |
| Template detail: "Learn about muscle priorities" link | template-detail-regan-grimes-ifbb-template.png | LRN-003, UI-009 |
| Template detail: "Plan a new mesocycle" button | template-detail-regan-grimes-ifbb-template.png | MESO-002 |
| Create flow: exercise per muscle slot from drop-downs | Hyp 2, old-web-meso-builder-empty-days-exercise-slots.png | MESO-013 |
| Create flow: flash icon auto-selects exercises | Hyp 2 | MESO-013 |
| Create flow: keep preselected exercises | Hyp 2 | MESO-013 |
| Create flow: add or delete exercises in a template | Hyp 2 | MESO-014 |
| Create flow: name, length, units, Create mesocycle | Hyp 2 | MESO-004 |
| Meso Builder: days per week | Hyp 2 | MESO-020 |
| Meso Builder: Emphasize, Grow, Maintain per muscle with limited emphasis slots | Hyp 2 | MESO-020, MESO-021 |
| Meso Builder: app builds the structure, then choose exercises | Hyp 2 | MESO-022 |
| Exercise order by priority and stimulus-to-fatigue ratio | Hyp 2 | MESO-046 |
| Reorder via day menu with "Apply to all remaining weeks" | Hyp 2 | MESO-045, WO-031 |
| Swap an exercise for a session, only for compelling reasons | Hyp 2 | EX-008 |
| Exercise library of 300+ exercises with search, filter, last-used date | exercise-library-300-exercises.png | EX-001, EX-003 |
| Exercise rows show last-used date | exercise-library-300-exercises.png | EX-013 |
| 250+ technique videos | Hyp 2 | EX-001 |
| Exercise detail: Detail tab (video, muscle, equipment, pinned notes) and History tab | exercise-detail-technique-video-pinned-notes.png | EX-002 |
| Recommended rep ranges 5 to 10, 10 to 20, 20 to 30 | Hyp 2 | EX-007 |
| Rep-range banner per exercise | Hyp 2 | WO-002 |
| Mesocycle summary: average sets per muscle by week with deload column | meso-summary-muscle-group-stats.png | MESO-040 |
| Mesocycles list with all past blocks | old-web-mesocycles-list.png | MESO-001 |
| Unfinished draft mesocycle is kept; starting a new build warns it replaces the draft | Hyp 6 | MESO-007 |

### Feedback

| Feature | Source | Requirement IDs |
|---|---|---|
| Single Feedback sheet after the last exercise for a muscle | post-exercise-feedback-joint-soreness-pump-volume.png | FB-001 |
| Joint pain per exercise, wording and four options | post-exercise-feedback-joint-soreness-pump-volume.png | FB-002 |
| Soreness per muscle, wording and four options | post-exercise-feedback-joint-soreness-pump-volume.png | FB-003 |
| Pump per muscle, wording and three options | post-exercise-feedback-joint-soreness-pump-volume.png | FB-004 |
| Volume per muscle, wording and four options | post-exercise-feedback-joint-soreness-pump-volume.png | FB-005 |
| Help "?" icon on each question | post-exercise-feedback-joint-soreness-pump-volume.png | FB-006 |
| Save and Cancel on the Feedback sheet | post-exercise-feedback-joint-soreness-pump-volume.png | UI-005, UI-022 |
| Soreness not asked in the first-ever session | Hyp 3.2 | FB-007 |
| "Your feedback is required" banner blocks progress | old-web-feedback-chest-soreness-prompt.png | WO-040 |
| Low pump, barely sore, easy workload leads to more sets | Hyp 3.3 | PROG-010 |
| Good pump, healing on time, pushed limits leads to no change | Hyp 3.3 | PROG-010, PROG-012 |
| Amazing pump, too sore, too much volume leads to fewer sets | Hyp 3.3 | PROG-010, PROG-013 |
| Calculations are continuous; added sets built on, removed sets dropped | Hyp 3.3 | PROG-010, PROG-019 |
| Soreness changes next session's sets: 6 sets and still sore gives 5; 3 sets and never sore gives 4 | Hyp 3.3 | PROG-014 |
| "A little sore" or "just healed" mostly does nothing | Hyp 3.3 | PROG-010 |
| Never sore is a signal only together with weak pump | Hyp 3.3 | PROG-010 |
| Extreme pump: probably no more set increases | Hyp 3.3 | PROG-010 |
| "Pushed my limits" is a hard stop on increases | Hyp 3.3 | PROG-012 |
| "Too much" reduces sets | Hyp 3.3 | PROG-013 |
| Week 1 volume answer should be easy; later just right; pushed by the last week (guidance) | Hyp 3.3 | FB-006, LRN-001 |
| Choose "pushed my limits" in a maintenance phase so sets do not grow | Hyp 3.3, 6 | PROG-016, PH-036 |
| Joint pain may not affect volume (unofficial claim) | Hyp 3.3 | PROG-018, EX-009 |
| Manual add or remove sets is the last chance to steer volume | Hyp 3.3, 4 | PROG-019 |
| Book algorithm: soreness 0 to 3 and performance 0 to 3 drive set changes | Hyp 3.3 | PROG-010, PROG-051 |
| Book: add 2+ sets when soreness low and performance rising; 0 to 1 when sore | Hyp 3.3 | PROG-010 |
| Book: consider recovery session, half-week or deload if performance falls | Hyp 3.3 | PROG-052 |
| Underperformance flag recommends easier training, user may ignore | Hyp 3.4 | PROG-050, PROG-051 |
| Two missed rep targets in a row leads to a recovery recommendation | Hyp 3.4 | PROG-050, PROG-052 |
| Repeatedly outside the rep range: app notifies or adjusts weight | Hyp 3.4 | PROG-053 |

### Load, reps and the workout screen

| Feature | Source | Requirement IDs |
|---|---|---|
| Week 1: no preset loads, user picks weight, reps shown with target RIR | Hyp 4 | WO-006 |
| Week 1: weight carries to the following sets, can be overwritten | Hyp 4 | WO-006 |
| Week 2 and later: target weight and reps pre-filled | old-web-target-load-and-reps-week2.png | WO-004, PROG-020 |
| Target icon shows while the plan is in use, disappears when weight is changed | Hyp 4 | WO-004 |
| Load rises a few percent a week | Hyp 4 | PROG-021 |
| Add a rep when the next increment is unavailable | Hyp 4 | PROG-022 |
| Dual progression: load, then reps | Hyp 4 | PROG-027 |
| Predictive matching: changed weight recalculates target reps | old-web-predictive-load-matching-weight-dropdown.png | WO-005, PROG-024 |
| Far from last weights: show only target RIR, "just log reps" | Hyp 4 | PROG-025 |
| Per-set rep targets descend within an exercise | Hyp 4 | PROG-026 |
| Start near MEV in week 1, 2 to 4 sets per session | Hyp 4, Hyp 2 | PROG-001 |
| Sets move toward MRV by priority and feedback | Hyp 4 | PROG-010, PROG-015 |
| Workout title shows week, day, label | Hyp walkthrough | WO-001 |
| Today's workout reached from the Workout tab | Hyp walkthrough | NAV-002 |
| Set row: menu, weight, reps, Log checkbox | in-workout-set-logging-set-menu-set-types.png | WO-003 |
| Reps info icon explains target | Hyp 5 | WO-007 |
| Exercise header: name, equipment, menu, history icon, rep-range banner with pin, pinned note | in-workout-set-logging-set-menu-set-types.png | WO-002 |
| Header calendar icon to look back through weeks | Hyp 5 | WO-008 |
| Day menu (three dots) | Hyp 5 | WO-031 |
| Set menu: Add set below | in-workout-set-logging-set-menu-set-types.png | WO-020 |
| Set menu: Skip set | in-workout-set-logging-set-menu-set-types.png | WO-020 |
| Set menu: Delete set | in-workout-set-logging-set-menu-set-types.png | WO-020 |
| Set type: Regular | in-workout-set-logging-set-menu-set-types.png | WO-021 |
| Regular set variants straight, down, ascending | in-workout-set-logging-set-menu-set-types.png | WO-021 |
| Set type: Myorep (M), 5 to 15 s pauses, log total reps | Hyp 5 | WO-021 |
| Set type: Myorep Match (MM), match first-set reps | Hyp 5 | WO-021 |
| Exercise menu: Add set (straight, myorep, myorep match) | Hyp 5 | WO-022 |
| Exercise menu: swap, pin note, add note, move, remove | Hyp 5 | WO-022 |
| No superset set type; pair back to back with notes or reorder | Hyp 5 | WO-030 |
| Dumbbells: enter one dumbbell's weight | Hyp 5 | WO-023 |
| Unilateral: weaker side first, match reps, log one side | Hyp 5 | WO-023 |
| No rest timer, rest-by-feel guidance (30 s to 5 min) | Hyp 5 | WO-030 |
| Pinned notes shown on every appearance | exercise-detail-technique-video-pinned-notes.png | EX-010 |
| Skip workout (irreversible), then finish | Hyp 5 | WO-050, WO-031 |
| Missed day: pick up where left or skip | Hyp 5 | SCH-057, WO-051 |
| Missed a week or more: restart or build new | Hyp 5 | SCH-058 |
| Reorder exercises, relabel days, copy changes to all remaining weeks | Hyp 5 | MESO-045, WO-031 |
| Finish workout | Hyp walkthrough | WO-040 |
| Web app installable to home screen | Hyp 5 | PLT-002 |
| Native iOS 17.6+ and Android | Hyp 5 | PLT-001 |
| iPad layout (RP has none) | Hyp 5 | PLT-006 |
| Training while sick help | Hyp 10 | WO-052 |
| Training alongside a sport help | Hyp 10 | WO-052 |
| Long workouts help (answer honestly or lower priority) | Hyp 10 | WO-052 |

### Between mesocycles

| Feature | Source | Requirement IDs |
|---|---|---|
| Finished meso: build the next or copy the previous | Hyp 6 | MESO-060 |
| Copy carries weights and targets when exercises unchanged | Hyp 6 | MESO-061, MESO-062 |
| Starting weights about 5% above last week 1, 2 to 2.5% advanced | Hyp 6 | MESO-061 |
| Light dumbbells keep weight and chase reps | Hyp 6 | MESO-063 |
| Maintenance phase: 1 to 3 sets, "pushed my limits" every time (manual in RP) | Hyp 6 | PH-036, PROG-016 |
| Active rest: deload plus one week off | Hyp 6 | MESO-064 |
| No pumps for a few weeks leads to active rest | Hyp 6 | PROG-054, MESO-064 |
| Build next meso in the week after the deload (6-month guides) | Hyp 6 | PLAN-027, MESO-060 |
| 6-month guided plans (muscle gain and fat loss) with mesocycle lengths and active rest | Hyp 6, book-6month-muscle-gain-periodization-graphic.png, book-6month-fat-loss-periodization-graphic.png | PLAN-030 |

### History and analytics

| Feature | Source | Requirement IDs |
|---|---|---|
| Muscle group stats (average sets per week by week and deload) | Hyp 7 | MESO-040, ANA-007 |
| Per-exercise History tab | Hyp 7 | EX-002, ANA-009 |
| Calendar icon to review past weeks and sessions | Hyp 7 | WO-008 |
| Target icon marks hit targets | Hyp 7 | WO-004 |

### Integrations, pricing, account

| Feature | Source | Requirement IDs |
|---|---|---|
| Apple Health and wearables (RP Hypertrophy has none) | Hyp 8 | INT-001, INT-003 |
| Diet app separate and unlinked | Hyp 8 | INT-005 |
| Monthly, yearly and 6-month plans | Hyp 9 | SUB-002 |
| 30-day money-back, one refund per customer, website only | Hyp 9 | SUB-006 |
| Store subscriptions managed through the store | Hyp 9 | SUB-005 |
| Manage web subscription and cancel | Hyp 9 | SUB-007 |
| Included Dr. Mike videos | Hyp 9 | LRN-001 |

## RP methodology


### Linking the two halves

| Feature | Source | Requirement IDs |
|---|---|---|
| Apps do not integrate; separate products, logins and subscriptions | Int 1 | ACC-002, SUB-001, INT-005 |
| No bundle, only a Diet discount | Int 1 | SUB-001 |
| Promo bundles ("both for under $1 a day") | Int 1 | SUB-009 |
| Same email for both apps | Int 1, 5 | ACC-002 |
| Diet app does not read the lifting schedule; calories shifted by hand to hard days | Int 1, 3 | SCH-040, NUT-004, NUT-014 |
| Diet app has no workout-time input | Int 1 | SCH-042, ONB-011 |
| Phase-matching: training and diet point in the same direction | Int 2 | PH-008, TBL-012 |
| Fat loss: every muscle set to Emphasize | Int 2 | MESO-021, TBL-012 |
| Mass: tiered Emphasize, Grow, Maintain with specialisation | Int 2 | MESO-021, PH-010 |
| Maintenance: flexible, balanced template | Int 2 | TBL-012, PH-036 |
| Warn on aggressive specialisation in a deficit | Int 2, 6 | PH-011 |
| Warn on a surplus with a low-volume plan | Int 2, 6 | PH-012 |

### Seven rules for training on a cut

| Feature | Source | Requirement IDs |
|---|---|---|
| Cut rule 1: do not specialise | Int 2, yt-01-training-on-a-cut-7-rules-slide.png | PH-011, MESO-021, TBL-012 |
| Cut rule 2: frequency at least 2x per week per muscle | Int 2 | MESO-023, TBL-012 |
| Cut rule 3: prefer high stimulus-to-fatigue exercises (rows, SLDL over conventional deadlifts) | Int 2 | MESO-016 |
| Cut rule 4: higher reps up to about 30 | Int 2 | TBL-012, EX-007 |
| Cut rule 5: MEV higher, start at 4 to 5 sets per session | Int 2 | PROG-002 |
| Cut rule 6: MRV lower, smaller progression steps (2.5 lb every session) | Int 2 | PROG-002, PROG-011, PROG-023 |
| Cut rule 7: deload must be eucaloric | Int 2 | PLAN-022 |
| Early about 14 sets per week rising to about 18 on a cut | Int 2, 07-article-lessons-from-fat-loss-mike-volume-mrv.png | PROG-002 |
| MRV on a cut about 4 to 6 sets below massing | Int 2 | PROG-002, PROG-003 |
| Keep volume up and use smallest load increases to prevent muscle loss | Int 2 | PROG-023, PROG-011 |
| Rep ranges biased to 10 to 20 on a cut | Int 2 | TBL-012 |
| Resensitization: maintenance with low volume near MV, 2 to 3 full-body sessions | Int 2, 4 | PH-036 |
| Hold muscle on a fraction of normal volume in maintenance | Int 2 | PH-036 |
| Steps: about 10,000 a day sweet spot, 12,000+ hurts lifting recovery | Int 2 | SAFE-015, REV-027 |
| HIIT limit of about 3 to 4 sessions per week; too much cardio or BJJ hurts lifting | Int 2, 6 | SCH-062 |
| Mini-cut volume right around MEV | Int 2 | PH-039 |
| Mass: start lower than usual MEV, end higher MRV | Int 2 | PROG-003 |
| Mass: more 5 to 10 rep work, high raw-stimulus lifts fine | Int 2 | TBL-012 |
| Mass: specialise 2 to 3 muscles | Int 7 | PH-010, MESO-021 |

### Deloads and fatigue

| Feature | Source | Requirement IDs |
|---|---|---|
| Deload every 4 to 6 weeks on a cut | Int 2 | PLAN-026 |
| Long accumulation on a cut; do not deload too often | Int 2 | PLAN-026 |
| Deload: first half sets and reps halved at same load | Int 2, 06b-deload-with-maintenance-calories.png | PROG-041 |
| Deload: second half load about halved | Int 2 | PROG-041 |
| Pair the deload with maintenance calories | Int 2 | PLAN-022 |
| Deloading in a deficit is a major mistake | Int 2, 6 | PLAN-023 |
| "It might not be the training, it might be the diet" | Int 2 | REV-041, PROG-052 |
| If volume must drop, cut one third to one half then rebuild one set a week | Int 2 | PROG-055 |
| Mass deloads eucaloric | Int 4 | PLAN-024 |
| Cut deload weeks double as diet breaks; a 10 to 12 week cut spans 2 to 3 mesos | Int 4 | PLAN-020, PLAN-022 |
| Strength stall on a cut: suspect diet first, deload at maintenance, then exit the diet | Int 5 | REV-041, REV-043 |
| Performance drops two sessions in a row means MRV reached | Int 5 | PROG-050, PROG-052 |

### Nutrition by training

| Feature | Source | Requirement IDs |
|---|---|---|
| Protein constant across phases | Int 3 | NUT-010 |
| Carbs scale with training volume | Int 3 | NUT-014, TBL-032 |
| Carbs 1.5 to 3.0 g per lb LBM on training days, 0.5 to 1.0 on rest days | Int 3 | TBL-032, NUT-016 |
| Fat at least 0.3 g per lb LBM | Int 3 | NUT-012 |
| Peri-workout: more carbs, less fat pre and post | Int 3 | NUT-031, NUT-032 |
| Pre-workout meal 1 to 3 hours before | Int 3 | SCH-042 |
| Post-workout meal soon after (within about 3 hours is fine) | Int 3 | SCH-042 |
| Intra-workout carbs for sessions over 1 hour | Int 3 | SCH-043 |
| Cut: cut fats first, hold carbs high for training | Int 3 | NUT-015 |
| Cut: bias carbs to the evening when hungry at night | Int 3 | NUT-037 |
| Deficit pace 0.5 to 1.0% per week | Int 3 | TBL-010 |
| Mass rate 0.25 to 0.5% per week | Int 3 | TBL-010 |
| Protein 1.5x for the first weeks after a cut | Int 3 | NUT-011 |
| No cheat foods for 1 to 4 weeks after a cut ends | Int 3, 6 | LRN-006 |

### Phase lengths and sequencing

| Feature | Source | Requirement IDs |
|---|---|---|
| Cut length 8 to 12 weeks, 6 to 9 sweet spot | Int 4 | TBL-010 |
| Add back 200 to 500 kcal per day every 3 to 4 weeks after a cut | Int 4, 6 | PH-044 |
| Maintenance 60 to 100% of cut length | Int 4 | PH-031 |
| Maintenance up to 200% for a chronic dieter; 25 to 50% if still losing on maintenance | Int 4 | PH-031 |
| Maintenance at least 2 weeks, up to 12 | Int 4 | TBL-010 |
| Maintenance "forever" if at target | Int 4 | PH-005 |
| Mass 8 to 16 weeks, no more than 16 unabated | Int 4 | TBL-010, SAFE-007 |
| Mini-cut 2 to 6 weeks, mass to mini-cut ratio 2:1 to 3:1 | Int 4, 08b-mass-to-minicut-ratio.png | TBL-010, PH-038 |
| Dedicated low-volume maintenance every 4 to 6 months for about a month | Int 4 | PH-045 |
| Annual cycle: mass, maintain, cut | Int 4, 7 | PLAN-006, PH-031, PH-033 |
| Mini-cut optional, skip if lean; body fat creep ends a mass | Int 5, 7 | PH-040, PH-002 |
| Post-cut maintenance should hold the new lower weight | Int 4 | PH-037 |
| Pick the phase first using entry conditions (low diet fatigue, low training fatigue, clear calendar) and readiness markers | Int 5, yt-05-which-phase-should-you-do-slide.png | PH-046 |
| Do not read the weight trend until a week into the plan | Int 5 | REV-032 |
| Phase length warning when a cut passes the recommended length | Int 4 | ONB-009, TBL-010 |
| Short maintenance (under 60% of the cut) before another cut is a mistake | Int 6 | PH-034 |
| Skipping maintenance is a mistake | Int 6 | PH-034 |

### Workflow and stalls

| Feature | Source | Requirement IDs |
|---|---|---|
| Template first; customising too early adds too much volume | Int 6 | ONB-012 |
| Pick days you can sustain, 3 to 4 beats a missed 6 | Int 5 | ONB-011 |
| Set meso length so the deload lands on the desired diet break | Int 5 | PLAN-020, PLAN-021 |
| Meso length 5 to 6 weeks on a cut, 5 to 8 on a mass | Int 5 | TBL-020 |
| Weekly review adjusts calories; move calories to hard days | Int 5 | REV-010, NUT-021 |
| Weight stall on a cut: cut calories or add steps, one change at a time | Int 5 | REV-027 |
| Do not cut training volume as the first lever | Int 5 | REV-041 |
| Weight stall on a mass: add calories; gaining too fast makes muscle resistant | Int 5 | REV-028 |
| Leaving a cut: calories added back gradually | Int 6 | PH-044 |
| Starting a cut already fatigued | Int 6 | PH-046 |
| Rushing back into a mass | Int 6 | PH-031, REV-028 |
| Cutting too fast or too long | Int 6 | SAFE-005, SAFE-006 |
| 5 to 10 lb jumps in a cut | Int 6 | PROG-023 |
| Pushing past a lower MRV | Int 6 | PROG-015 |
| Dropping to MV on a cut while still progressing | Int 6 | PROG-016, REV-041 |

## Deliberately not carried

Old or removed behaviour, behaviour the spec replaces on purpose, and items the overview puts out of scope. None of these is a gap.

| Item | Source | Why not carried | Superseded by |
|---|---|---|---|
| Last meal about 45 min before bed (old logic, unofficial) | Diet 5 | Old pre-v1.5 meal-timing logic from unofficial reviews. Superseded by eating windows and peri-workout placement. | SCH-041, SCH-042 |
| Cardio under an hour does not count, cardio-only days are rest days (old UI, unofficial) | Diet 5 | Old-UI behaviour. Superseded by Other activity, which logs sport and cardio for nutrition with a chosen intensity. | SCH-032, NUT-005 |
| Sleep schedule input (pre-v1.5) | Diet 1 | Replaced in v1.5 by eating windows and busy periods. | SCH-041, SCH-045, ONB-014 |
| Macros only with calories hidden (pre-Sep 2025) | Diet 1 | Pre-Sep 2025 design. Calories are visible everywhere. | SCH-002, SCH-006, SCH-020 |
| Meals built in steps 1-2-3: protein, then fat, then carb from RP lists (pre-v1.5) | Diet 1 | Pre-v1.5 builder replaced by single-screen adding with a basket and an optimizer. | FOOD-028, MEAL-002 |
| Fixed daily macro targets per day (pre-v1.5) | Diet 1 | Pre-v1.5 design. Replaced by the weekly calorie goal with movable days. | NUT-020, NUT-021 |
| Snacks had to be logged inside an existing meal (old UI) | Diet 14 | Old-UI limit. Replaced by Add meal and off-plan entries. | SCH-031, DAY-001 |
| 1:1 coaching and diet templates sold separately | Diet 12 | Separate RP products, not app features. Out of scope. | 00-overview scope |
| Bedtime and wake-time hacks to shift meals (old UI, unofficial) | Diet 5 | Old-UI workaround for the sleep schedule. Hand-set meal times replace it. | SCH-044 |
| Meals marked "roughly at, over, under" (pre-v1.5) | Diet 7 | Pre-v1.5 at, over, under check-ins. Replaced by the 5 g on-track check. | MEAL-043 |
| Adherence score green at 90% or more (old, unofficial) | Diet 7 | Old unofficial score. Replaced by the 10% day status and green dots. | DAY-010, ANA-011 |
| No onboarding questionnaire; first act is building a mesocycle | Hyp 1 | Design choice not carried. The unified setup asks experience and training days. | ONB-010, ONB-011, ONB-012 |
| Units chosen per meso and locked | Hyp 1, 6 | Locked units are a known user complaint. One app-wide unit setting is used. | SET-001 |
| Plan a mesocycle screen asks sex, start weekday, preset or custom (2023 alpha) | Hyp 1, old-web-plan-a-mesocycle-sex-startday-preset.png | 2023 alpha screen. Replaced by the template library filters and the setup steps. | MESO-011, MESO-004, ONB-010, ONB-011 |
| Body-part presets such as "lower body three times a week" (2023 alpha) | Hyp 1 | 2023 alpha presets. Replaced by templates filtered by days and the Meso Builder. | MESO-010, MESO-011, MESO-020 |
| Cycle every 4th, 5th or 6th training week; web could not extend (2024 help) | Hyp 2 | 2024 help wording and a web limit. Replaced by chosen length with mid-meso extension. | TBL-020, MESO-042 |
| Primary and secondary priorities (before v0.31) | Hyp 2 | Replaced by Emphasize, Grow and Maintain in v0.31. | MESO-030, MESO-031 |
| Changing a slot's exercise loses that slot's history | Hyp 2, 6 | Slot-bound history is a known complaint. History follows the exercise. | MESO-062 |
| Soreness asked right after the first set (2023 alpha) | Hyp 3.2 | 2023 alpha prompt timing. Replaced by one Feedback sheet per muscle. | FB-001, FB-003 |
| Old wording: A little sore, I just healed; workload Easy, Pretty good | old-web-feedback-joint-pump-workload-prompt.png, old-web-feedback-workload-easy-prettygood-pushedmylimits-toomuch.png | 2023 alpha wording. Current wording is used. | FB-003, FB-005 |
| Offline use (RP has none) | Hyp 5 | Out of scope for v1 per 00-overview. Offline logging is a v2 candidate. | 00-overview scope |
| Weight dropdowns and check circles on set rows (2023 web) | old-web-in-workout-set-logging-check-circles.png | 2023 web controls. Replaced by numeric fields built for the gym. | WO-003, UI-020 |
| Copy loads came with empty weights (2023 beta) | Hyp 6 | 2023 beta behaviour. Copying now carries weights and a start-load bump. | MESO-061 |
| No link to the diet phase (RP) | Hyp 6, 8 | The point of this product. One plan drives both halves. | PLAN-001, PH-008, PLAN-022 |
| Long-term progress charts (RP has none) | Hyp 7 | Out of scope for v1 per 00-overview. Per-exercise history only. | ANA-009, 00-overview scope |
| Coach client portal and messaging | Hyp 8 | Out of scope for v1 per 00-overview. | 00-overview scope |
| Discount on Diet for Hypertrophy subscribers | Hyp 8 | Replaced by one subscription for everything. | SUB-001 |
| Included eBooks | Hyp 9 | Content products, not app behaviour. Learn holds the articles and videos. | LRN-001 |

Carried although the research tags it old or unconfirmed: the Hypertrophy deload that removes traps and forearms (PROG-042), the legacy underperformance flag (PROG-050 to PROG-053) and the legacy out-of-rep-range adjustment (PROG-053). The spec keeps them because RP's own current help text still relies on them.

## GAPS

No open gaps as of draft 2
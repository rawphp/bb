# 13. Navigation and shared UI

The two RP apps look and behave differently. Here, training and nutrition use the same components. A screen only looks different where the task is different. Logging a set and logging a food are different jobs, but the cards, sheets, review steps and status bars around them are shared.

## Navigation (NAV)

- **NAV-001** [I] The bottom bar must have five tabs: Today, Train, Nutrition, Progress, More.
  - **Today**: the day timeline (04-schedule.md). Home screen.
  - **Train**: Plan timeline at the top, then the current mesocycle, Mesocycles, Templates and Exercises.
  - **Nutrition**: today's meals in a list, the Pantry, Saved meals, Recipes and the Shopping list.
  - **Progress**: weight, phases, mesocycles and the review card.
  - **More**: the menu in SET-020.
- **NAV-002** [I] Tapping a workout card on Today and opening today's session from Train must reach the same workout screen. Tapping a meal on Today or Nutrition must reach the same meal screen.
- **NAV-003** Deferred, not in v1 (iPhone only). [H] On wide screens (web, iPad), the bottom bar becomes a left rail.
- **NAV-004** [G] An active workout must show a pinned bar above the tab bar ("Upper A · 4 of 6 exercises") so the user can leave the workout and come back.

## Shared components (UI)

Where a component is shared, both meals and workouts must use it.

- **UI-001** [I] **Week strip.** One component on Today, Nutrition and the weekly review. It shows day letter, date, calories, completion dot and training icon.
- **UI-002** [I] **Item card.** Meals, workouts, weigh-ins and busy periods use one card layout: an icon, a title, a time on the right, a status control (checkbox for meals, Start/Done for workouts, value for weigh-ins), and a body. The meal body is four macro bars. The workout body is the exercise list with set counts.
- **UI-003** [I] **Progress bars.** Macro bars (calories, P, F, C) and set bars (sets done against planned) must use the same bar component with value/target labels like "45/40".
- **UI-004** [I] **Status pill.** "on track", "315 under", "Targets met", "At your recovery limit", "Deload" and "Adjust foods" must use one pill component with four colours: good, neutral, warning, info.
- **UI-005** [I] **Bottom sheet.** Copy foods, Add to schedule, the set menu, the meal menu, the Feedback sheet and the Adjust sheet must all be bottom sheets with Cancel on the left, the title in the middle and Save on the right.
- **UI-006** [I] **Choice list.** Feedback answers, weekly review options, goal choices and setup choices must use the same single-choice list (full-width rows, one selected, "Recommended" tag where relevant).
- **UI-007** [I] **Stepper flow.** Onboarding, the weekly review, the create-mesocycle flow and phase transition must use one step-flow layout: step counter, title, content, Back and Next.
- **UI-008** [I] **Row with value fields.** A food row (name, raw/cooked, grams) and a set row (weight, reps, log) must share one row layout: label, then right-aligned numeric fields, swipe actions, a menu on the left.
- **UI-009** [I] **Help icon.** One ? icon that opens a short sheet with a "Read more" link to Learn.
- **UI-010** [I] **Toast with Undo.** Used for every automatic change: re-targeted meals, sets added, redistribution.
- **UI-011** [D] **Colours.** Calories, protein, fat and carbs keep fixed colours everywhere (blue, red, orange, green in RP Diet). Training uses the same accent colour as the app, not a second palette.

## Where the UI differs on purpose (UI)

- **UI-020** [H] The workout screen must be optimised for the gym: large tap targets (at least 48 pt), numeric keypad for weight and reps, no swipe gestures that could fire by accident while holding weights.
- **UI-021** [D] The meal screen keeps food search and the camera button at the top, since adding foods is the main task.
- **UI-022** [H] The Feedback sheet must not close on a tap outside it, so answers aren't lost.

## Accessibility (UI)

- **UI-030** [G] All text must support Dynamic Type and Android font scaling. All controls must have screen-reader labels. Colour must never be the only signal, so pills and bars also carry text.
- **UI-031** [G] Contrast must meet WCAG AA in light and dark mode.

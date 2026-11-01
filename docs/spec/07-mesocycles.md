# 07. Mesocycles and exercises

A mesocycle is 4 to 8 weeks of training whose last week is a deload. This follows RP Hypertrophy, except that the phase now sets the defaults.

## Creating a mesocycle (MESO)

- **MESO-001** [H] The Mesocycles screen must list all mesocycles (current first, then planned, then past) with name, dates, length and phase.
- **MESO-002** [H] A + button must offer four ways to create one: Template, Copy previous mesocycle, Meso Builder, Build from scratch.
- **MESO-003** [I] Every new mesocycle must belong to a phase. By default that is the phase covering its start date. Its muscle priorities, rep-range bias and volume offsets start from that phase's defaults (TBL-012).
- **MESO-004** [H] The create flow must ask for name, length in weeks including the deload (default from TBL-020, max 8), and start date.
- **MESO-005** [H][I] Sessions must be ordered (Day 1, Day 2...). By default they map to the user's training weekdays (SCH-040). Users can label days with weekday names or "Day 1, Day 2" for a floating split. Days must be done in order.
- **MESO-007** [H] An unfinished mesocycle build must be saved as a draft. Starting another build must warn that it replaces the draft.

## Templates (MESO)

- **MESO-010** [H] The template library must hold at least 45 templates, from 2-day full body to 6-day specialisations, including named athlete programs.
- **MESO-011** [H] Templates must be filterable by days per week, sex, whole-body or emphasis, and phase fit (cut, gain, maintain) [I].
- **MESO-012** [H] Template detail must show days, exercises per day by muscle slot, muscle priorities and weekly sets per muscle.
- **MESO-013** [H] When using a template, each muscle slot must offer an exercise from a drop-down, a "pick for me" button (flash icon) that fills every empty slot, or keep the preselected exercise.
- **MESO-014** [H] Users must be able to add or delete exercises in a template before creating the mesocycle.
- **MESO-015** [H][G] Each template must carry a split label (full body, upper/lower, push/pull/legs, specialisation), shown in the library and on template detail, so users can find familiar splits.
- **MESO-016** [M][G] Each exercise must carry a stimulus-to-fatigue rating (high, medium, low). "Pick for me" and template suggestions must prefer high-rated exercises during Fat loss (for example rows and stiff-leg deadlifts over conventional deadlifts).

## Meso Builder (MESO)

- **MESO-020** [H] The Meso Builder must ask for days per week and a priority for each muscle group: Emphasize, Grow or Maintain.
- **MESO-021** [H][G] The builder must suggest at most 2 Emphasize muscles during Muscle gain when others are Grow or Maintain, and all muscles at Emphasize during Fat loss (TBL-012). Going past these must warn and not block (PH-010, PH-011).
- **MESO-022** [H] The builder must then generate the session structure (which muscles on which days, how many slots each) and show it building. The user then picks exercises as in MESO-013.
- **MESO-023** [M][G] Generated structures must train each muscle at least twice a week during Fat loss, and slower-recovering muscles (back, quads, hamstrings, glutes) 2x and faster ones (delts, arms, calves, abs) up to 3x when days allow.

## Muscle priorities (MESO)

- **MESO-030** [H] Each muscle group must have a priority shown as bars: Emphasize (3), Grow (2), Maintain (1), and Off (0) for a muscle the user can't train (MESO-034).
- **MESO-031** [H] Emphasize must add volume whenever recovery allows, moving toward MRV. Grow must add volume only when feedback says it is needed, staying near MEV. Maintain must hold at MV. The rules are in PROG-010.
- **MESO-032** [H] Users must be able to change a muscle's priority mid-mesocycle from the workout menu ("Progression rates"). The change applies from the next session.
- **MESO-033** [H] Muscle groups must be: chest, back, quads, hamstrings, glutes, side delts, rear delts, front delts, biceps, triceps, calves, abs, traps, forearms.
- **MESO-034** [I][G] Users must be able to set a muscle to Off mid-mesocycle when injury or pain stops them training it, from the same menu as MESO-032. From the next session:
  - its slots are left out of every session, and its feedback questions are not asked;
  - a session with no slots left is not planned, so its day becomes a rest day;
  - MESO-023's frequency rule and the PH-010 and PH-011 warnings ignore it.

  Day types and calories follow the smaller sessions, because a day's intensity comes from its planned sets (TBL-031). Its history and pinned notes stay (MESO-062). Setting it back to a priority restarts its sets at the phase's week-1 volume (PROG-001), not where it stopped.

## Mesocycle view and editing (MESO)

- **MESO-040** [H] The mesocycle view must show week columns, each day's exercises, and a summary of average weekly sets per muscle for each week including the deload.
- **MESO-041** [I] The mesocycle view must also show the phase, the weekly calorie target for each week, and which week is a diet break. Whether a week is a diet break follows the phase covering that week (PLAN-002), not the mesocycle's own phase.
- **MESO-042** [H] Users must be able to change the length mid-mesocycle with + and − (calendar icon), keeping the deload as the last week.
- **MESO-043** [H] Users must be able to end a mesocycle early ("End meso"). The app must warn that skipping the deload is not recommended for intermediate and advanced lifters.
- **MESO-044** [I] Ending a mesocycle early during Fat loss must offer to start a diet-break week now (PLAN-022).
- **MESO-045** [H] Users must be able to add a day, relabel days and reorder exercises from the day menu, with an "Apply to all remaining weeks" checkbox.
- **MESO-046** [H] Exercise order must default to priority first, then by stimulus-to-fatigue ratio, compounds before isolations.

## Next mesocycle (MESO)

- **MESO-060** [H][I] During a deload week, the app must prompt "Build your next mesocycle", offering Copy this mesocycle (default), a template matching the phase that week 1 of the next mesocycle falls in, or the builder.
- **MESO-061** [H] Copying must carry over the structure and the weights. Starting loads must rise from the old week 1 by the copy load rise of the phase covering the new week 1 (TBL-012), rounded to the equipment step. Differs from RP, which always adds about 5%.
- **MESO-062** [G] Exercise history must follow the exercise, not the slot. Swapping an exercise in a copied mesocycle keeps history for exercises that stay. Differs from RP.
- **MESO-063** [H] Light dumbbell movements whose next step is too large must keep the same weight and chase reps instead.
- **MESO-064** [M][H] After a mesocycle where the user reported no pump and no soreness for most muscles for 2 or more weeks, the app must recommend an active rest: the deload week plus a week off.
- **MESO-065** [I] When the phase covering a copy's week 1 has a different type from the old mesocycle's phase, the copy must take the new phase's priority preset (TBL-012) instead of the old priorities. Structure and exercises still carry over. The create flow must say so ("Priorities reset for Fat loss") and offer to keep the old priorities, which then get the PH-010 and PH-011 warnings.

## Exercises (EX)

- **EX-001** [H] The exercise library must hold at least 300 exercises, each with name, target muscle group, equipment, and a technique video. At least 250 must have videos.
- **EX-002** [H] Each exercise must have a Detail tab (video, muscle, equipment, pinned notes) and a History tab (every set ever logged, by date).
- **EX-003** [H] The library must be searchable and filterable by muscle group and equipment.
- **EX-004** [G] Users must be able to create custom exercises with name, muscle group, equipment and load type.
- **EX-005** [H] Each exercise must have a load type that sets how weight is entered and stepped (TBL-040): barbell, dumbbell (one dumbbell's weight), machine, cable, bodyweight, bodyweight plus load, smith machine.
- **EX-006** [I] Bodyweight and bodyweight-plus-load exercises must use the user's latest trend weight from weigh-ins as the base load when computing targets.
- **EX-007** [H] Each exercise in a mesocycle must show a recommended rep range: 5 to 10 for big compounds, 10 to 20 for isolations and machines, 20 to 30 for small muscles or awkward-to-load movements. The phase can shift this (TBL-012).
- **EX-008** [H] Users must be able to swap an exercise for one session or for the rest of the mesocycle. Help text must advise swapping only for a good reason.
- **EX-009** [I][G] When an exercise gets "Moderate" or "A lot of pain" joint feedback twice running, the app must suggest swapping it for another exercise for the same muscle.
- **EX-010** [H] Users must be able to pin a note to an exercise. It shows every time the exercise appears.
- **EX-011** [H] Help content must cover home gyms. Limited equipment is handled by choosing exercises, since the app does not ask about equipment.
- **EX-012** [G] Users must be able to set the load step for any exercise (for example a 4.5 kg cable stack) and turn on micro plates in Settings, which halves barbell steps (TBL-040).
- **EX-013** [H] Each exercise row in the library must show the date it was last used.

# 03. The plan and phases

The plan is a timeline of phases. Each phase holds one or more mesocycles. RP keeps diet phases in one app and mesocycles in another; here they sit on the same timeline and are kept aligned.

## Plan timeline (PLAN)

- **PLAN-001** [I] Each user must have one plan: an ordered list of phases, each with a type, start date, end date and target rate.
- **PLAN-002** [I] Phases and mesocycles must sit on the same week grid (PLAN-008). A mesocycle belongs to the phase covering its first week. A mesocycle may run past the end of its phase. When it does, or when the phase ends early or changes type, its remaining weeks follow the new phase's rules from the next week: progression offsets (PH-009) and deload calories (TBL-012).
- **PLAN-003** [I] The Plan screen (Train tab, top) must show the timeline as horizontal bands: phases on top, mesocycles under them, deload weeks marked, and today marked.
- **PLAN-004** [I] Tapping a phase must open its detail: type, dates, rate, start and target weight, progress so far, and actions (adjust length, end phase, change goal).
- **PLAN-005** [I] Tapping a mesocycle on the timeline must open the mesocycle (MESO-040).
- **PLAN-006** [I][G] Users must be able to add a future phase to the end of the plan. Only the current phase and the next one can be scheduled in detail. Later phases are outlines that become concrete when they start.
- **PLAN-007** [I][G] The app must keep the plan in training-only mode too. Phases then set training defaults only. They have no rate, and they default to the length of one mesocycle (TBL-020), repeating until the user changes them.
- **PLAN-008** [I] There must be one plan week shared by nutrition, training and the review. It starts on the review day (ONB-016) and runs 7 days. The calorie week, the mesocycle week and the review all use it. Every mesocycle and phase must start on a plan-week boundary. If the plan's start date isn't on the boundary, the first week is a short week. Changing the review day takes effect from the next boundary, with one week shortened or lengthened to fit.
- **PLAN-009** [I] Wherever a requirement says "Fat loss", it also applies to Mini-cut, unless TBL-012 gives Mini-cut its own value.

## Phase types (PH)

- **PH-001** [D] Phase types must be Fat loss, Muscle gain and Maintenance.
- **PH-002** [M] The app must also offer Mini-cut, a short fat-loss phase run between muscle-gain phases.
- **PH-003** [D][M] Rates and lengths must follow TBL-010.
- **PH-004** [D] Maintenance must default to zero weight change, and must allow a small gain or loss target within ±0.25% per week.
- **PH-005** [D] A Maintenance phase may have no end date.
- **PH-006** [D] Users must be able to lengthen or shorten the current phase ("Adjust phase length", More menu and phase detail), within TBL-010 limits.
- **PH-007** [D] Users must be able to end the current phase early. Ending it must open phase transition (PH-030).
- **PH-008** [I] Every phase type must set training defaults, listed in TBL-012:
  - muscle priority preset;
  - template recommendation;
  - starting volume offset;
  - volume ceiling offset;
  - weekly set-progression step;
  - load-progression step;
  - preferred rep ranges;
  - minimum frequency.

  These rules come from Dr. Mike's cutting guidance and *An Intro to Volume Landmarks*, p.111.
- **PH-009** [I] Changing the current phase type must re-apply the training defaults to the next mesocycle. The current mesocycle keeps its structure, and the progression offsets switch from the next week. Workout progression (PROG-002, PROG-011, PROG-016, PROG-021, PROG-023) must read the phase covering each week (PLAN-002), so training switches in the same week as calories.
- **PH-010** [M] Muscle gain must warn, and not block, when the user specialises more than 2 muscle groups.
- **PH-011** [M] Fat loss must warn, and not block, when the user sets any muscle to Grow or Maintain while others are Emphasize ("Specialising during a cut works against recovery").
- **PH-012** [M] Muscle gain must warn, and not block, when the current mesocycle uses a Maintain or resensitization preset, or a volume ceiling below the Muscle gain default ("A surplus with a low-volume plan wastes the phase").

## Mesocycle alignment (PLAN)

- **PLAN-020** [I] When a phase is created, the app must propose mesocycle lengths that fill it, using TBL-020 for the user's experience level. The phase's last week must coincide with a mesocycle's deload week where possible. A phase with no end date gets one mesocycle at a time, each at the TBL-020 default, with the next proposed during the deload (PLAN-027).
- **PLAN-021** [I] If a phase's end date falls mid-mesocycle, the app must offer two fixes: move the phase end to the nearest deload week (within 2 weeks), or change the mesocycle length. The user picks one.
- **PLAN-022** [I] During Fat loss and Mini-cut, every deload week must run at maintenance calories automatically (a "diet break"). The schedule, weekly review and week strip must label the week "Deload · maintenance calories".
- **PLAN-023** [I] The user must be able to turn PLAN-022 off for one deload week. The app must warn that deloading in a deficit is "one of the biggest mistakes" RP warns about.
- **PLAN-024** [I][M] During Muscle gain and Maintenance, deload weeks must also run at maintenance calories, since RP says mass deloads are eucaloric. In Muscle gain these are planned weeks and don't move the end date.
- **PLAN-025** [I] Diet-break weeks must not count toward the phase's weekly rate or toward the 16-week cap (SAFE-006), which counts deficit weeks only. Deloads planned by PLAN-020 are already inside the phase length. Only unplanned breaks (Drop fatigue, PROG-052, SCH-060) must move the end date out by one week each, unless the user keeps the end date.
- **PLAN-026** [M][G] Fat-loss phases longer than 8 weeks must include at least one deload week at maintenance calories. The proposal in PLAN-020 must place deloads 4 to 6 weeks apart.
- **PLAN-027** [I] When a mesocycle ends, the app must open "Build your next mesocycle" (MESO-060) during the deload week. Per RP's 6-month guides, this is when to build the next one.
- **PLAN-028** [I][G] Setting or moving a phase's event date (PH-043) must re-run PH-042's check. When the phase's last week isn't the event's plan week, the app must offer to move the phase end there, within TBL-010 limits. Accepting re-proposes the lengths of the mesocycles that haven't started (PLAN-020), so the event week is a deload where possible. A running mesocycle goes through PLAN-021. The phase's rate doesn't change.
- **PLAN-030** [H][M] The app must offer RP's two prebuilt 24-week plans, which lay out the phase, mesocycles and active rest on the timeline:
  - Muscle gain: mesocycles of 5, 4, 4 and 4 weeks, then 2 weeks of active rest, then a 6-week mesocycle at fat loss. Gain at 0.5% per week, then 0.375%.
  - Fat loss: three 5-week mesocycles, active rest, then two 4-week mesocycles. Lose at 0.75% per week.

## Phase transitions (PH)

- **PH-030** [D][I] When a phase ends (on its end date, or when ended early, or when the weekly review chooses "End phase"), the app must show a transition screen with:
  - a summary of the phase: weight change, weekly rate achieved, adherence, and mesocycles completed;
  - the recommended next phase;
  - the choice to pick a different next phase.
- **PH-031** [D][M] After Fat loss, the recommended next phase must be Maintenance. Its length is 2/3 to 1x the fat-loss length, rounded to whole mesocycles. The default is 1x when the user has run 2 or more fat-loss phases in the past 12 months, and 2/3x otherwise. The user may set up to 2x (for chronic dieters), or as little as 1/4 to 1/2 when trend weight is still falling on maintenance calories.
- **PH-032** [M][I] After Fat loss, the app must also offer "Gain first". This is one Muscle gain mesocycle straight after the cut, then a Maintenance mesocycle with resensitization training, then muscle gain again. *Scientific Principles*, pp.281-282, describes it. The default stays Maintenance.
- **PH-033** [D] After Muscle gain, the recommended next phase must be Maintenance (4 to 8 weeks) or a Mini-cut.
- **PH-034** [D][M] After Maintenance, the app must offer Fat loss or Muscle gain. It must not recommend Fat loss right after another Fat loss without a Maintenance phase in between. It must warn when a Fat loss is scheduled after a Maintenance shorter than 60% of the previous Fat loss.
- **PH-035** [D] A new phase must take its starting calories from the previous phase's measured expenditure (REV-021), not from the setup formula. If no valid measured estimate exists (previous phase under 3 weeks, too few logged weeks, or training-only mode), it uses the formula (NUT-002).
- **PH-036** [M][I] Maintenance after Fat loss must pair with a resensitization mesocycle by default: every muscle at Maintain, 2 or 3 full-body sessions a week, 1 to 2 sessions per muscle per week, near MV, 3 to 4 weeks. The user can pick a balanced template instead.
- **PH-037** [M] Maintenance after Fat loss must hold weight at the new lower trend weight, not return to the pre-diet weight. The weekly review keeps trend weight inside the maintenance corridor (REV-029).
- **PH-038** [M] Mini-cut must warn when the ratio of the previous muscle-gain length to the mini-cut length is below 2:1.
- **PH-039** [M][G] Mini-cut training must default to all muscles at Emphasize with a ceiling of MEV +2 sets per week (TBL-012), so volume stays "right around MEV and usually not much higher".
- **PH-040** [M] During Muscle gain the app must warn, at the weekly review, when estimated body fat (start estimate plus fat share of weight gained, TBL-013) passes 20% for men or 30% for women, and recommend a Mini-cut or Fat loss.
- **PH-041** [D] At the end date, the final weekly review must offer "Keep goal" (extend, up to the TBL-010 maximum), "Choose new goal" and "End plan".
- **PH-042** [D] A phase-length check must warn when the chosen end date runs past a target date the user set for an event, as in RP's wedding example.
- **PH-043** [D][G] Users must be able to set an optional event date (competition, wedding, holiday) on a phase. The app counts down to it on the Plan screen.
- **PH-044** [M][G] After Fat loss, Maintenance must step calories back up by default: start at the last fat-loss target plus 250 to 500 kcal a day, and add another step every 3 weeks until measured maintenance (REV-021) is reached. The screen explains "a 12-week diet is a 16-week process". The user may choose "Go straight to maintenance" instead, which is how RP Diet behaves.
- **PH-045** [M][G] When the plan has had no Maintenance phase in the past 5 months, the Plan screen must suggest a 4-week low-volume Maintenance phase.
- **PH-046** [M][G] Before a new Fat loss or Muscle gain phase starts, the app must ask whether diet fatigue and training fatigue are low and the calendar is clear. It must also check readiness: fat at least 0.3 g per lb of lean mass, and training-day carbs at least 1.5 g per lb. Any failure warns and recommends Maintenance first.

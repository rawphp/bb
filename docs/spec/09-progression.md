# 09. Progression rules

RP calls its algorithm proprietary. These rules follow what RP has said in public: the help-centre summary, Dr. Mike's app videos, *Scientific Principles* pp.97-98 and 121-122, and *An Intro to Volume Landmarks* p.111. The numbers are best guesses, collected in TBL-020 to TBL-040 and TBL-051.

All feedback for a muscle in a session changes that same session's next weekly occurrence (Week 3 Day 2 sets Week 4 Day 2). This matches RP. A muscle trained twice a week therefore has two independent set counts.

## Volume: sets per muscle (PROG)

- **PROG-001** [M] Week 1 volume must start near MEV for each muscle (TBL-021), adjusted by experience level and phase (TBL-012). That is usually 2 to 4 sets per muscle per session.
- **PROG-002** [M][I] During Fat loss, starting volume must be higher than normal (MEV +2 sets per week per muscle) and the ceiling lower (MRV −2 sets per week). *An Intro to Volume Landmarks*, p.111: "start at something like 14 sets per week, and stop at something like 18".
- **PROG-003** [M][I] During Muscle gain, starting volume must be lower than normal (MEV −1 set per week) and the ceiling higher (MRV +2).
- **PROG-010** [H][G] After each session, the sets for that muscle in the same session next week must change by a score built from its feedback (TBL-022):

  | Answer | Points |
  |---|---|
  | Soreness: Never got sore | +1 |
  | Soreness: Healed a while ago | +0.5 |
  | Soreness: Healed just on time | 0 |
  | Soreness: I'm still sore! | −1 |
  | Pump: Low | +1 |
  | Pump: Moderate | 0 |
  | Pump: Amazing | −0.5 |
  | Volume: Not enough | +1 |
  | Volume: Just right | 0 |

  The positive soreness points (Never got sore, Healed a while ago) only count when the pump was Low or the volume was Not enough. Dr. Mike says never getting sore is fine on its own and only a signal alongside a weak pump.

  Then, by priority:
  - **Emphasize**: score of 1 or more adds 1 set; 2 or more adds 2 sets (1 during Fat loss, PROG-011).
  - **Grow**: score of 2 or more adds 1 set.
  - **Maintain**: never adds sets.
  - All priorities: score of −1 or less removes 1 set.
- **PROG-011** [M][I] During Fat loss, a muscle must gain no more than 1 set per session per week. *Volume Landmarks*, p.111: "only add 1 set or so during each microcycle, instead of the 2 sets".
- **PROG-012** [H] "Pushed my limits" must stop any increase for that muscle at the next occurrence of the session, whatever the other answers.
- **PROG-013** [H] "Too much" must remove 1 set at the next occurrence, whatever the other answers.
- **PROG-014** [H] Dr. Mike's examples are the acceptance tests, with every input stated:
  - Grow priority, Muscle gain, 6 sets, "I'm still sore!", Moderate pump, Just right. Score −1, so next week is 5 sets.
  - Grow priority, Muscle gain, 3 sets, "Never got sore", Low pump, Just right. Score +2, so next week is 4 sets.
  - The same answers at Emphasize give 5 sets in Muscle gain (+2) and 4 sets in Fat loss (+1, PROG-011).
- **PROG-015** [M][I] A muscle's weekly sets must never exceed its phase-adjusted MRV (TBL-021 plus PROG-002 or PROG-003). At the ceiling, the app holds volume and shows "At your recovery limit for {muscle}".
- **PROG-016** [I][G] Maintain-priority muscles must stay at the phase-adjusted MV. MV is 1 set higher during Fat loss and 1 lower during Muscle gain, per *Volume Landmarks* ("even the MV rises when hypocaloric").
- **PROG-017** [G] Added sets must go to the exercise for that muscle with the best joint feedback, up to 5 sets per exercise per session. If every exercise is at 5, the app suggests adding an exercise.
- **PROG-018** [I][G] Joint pain of Moderate or A lot must stop added sets going to that exercise. A lot of pain must also cut the next session's load on that exercise by 10%.
- **PROG-019** [H] Users must be able to add or remove sets by hand at any time. Added sets carry forward and removed sets are dropped from future sessions.

## Load and reps (PROG)

- **PROG-020** [H] From week 2, each set must have a target weight and target reps built from the same set last week.
- **PROG-021** [H][G] Target weight must rise about 2% per week (1% during Fat loss), rounded to the exercise's equipment step (TBL-040).
- **PROG-022** [H] When rounding leaves the weight unchanged (the step is too large), the target must add 1 rep to each set instead.
- **PROG-023** [M][I] During Fat loss, weight steps must use the smallest increment the equipment allows ("2.5 lb every session as opposed to every five").
- **PROG-024** [H][G] When the user enters a different weight, target reps must be recalculated to an equal effort using estimated one-rep max: e1RM = weight × (1 + (reps + RIR) / 30). Target reps = 30 × (e1RM / new weight − 1) − RIR, rounded down. e1RM comes from the planned target, not last week's set. Acceptance example from Dr. Mike's demo: the target is 140 × 26 at 2 RIR. Entering 145 must give 24 reps, as RP does. Entering 135 gives 28 here, where RP showed 27; that one-rep gap is accepted.
- **PROG-025** [H] If the new weight is more than 15% away from last week's, the app must show only the target RIR ("1 RIR, just log reps") and build next week from what was done.
- **PROG-026** [H][G] Later sets of an exercise must have lower rep targets than the first, matching last week's drop-off between sets. In week 1 the default drop is 2 reps per set.
- **PROG-027** [H] Targets must stay within the exercise's rep range. When reps would pass the top of the range, the weight must go up one step and reps reset to the middle of the range.

## Effort: RIR by week (PROG)

- **PROG-030** [H][G] Target RIR must start at 3 in week 1 and reach 0 in the last training week. The schedule for each length is TBL-023.
- **PROG-031** [H] Users cannot change the RIR schedule. They can aim differently and log the reps they did.

## Deload (PROG)

- **PROG-040** [H] The last week of every mesocycle must be a deload. It is automatic.
- **PROG-041** [M][G] Deload sessions in the first half of the week must halve sets and reps (rounded down, minimum 1 set) at the same weight. Sessions in the second half must also halve the weight.
- **PROG-042** [H] Traps and forearms must be removed in the deload.
- **PROG-043** [H] Help text must say the deload is meant to feel easy, and to push the 0 RIR week as hard as possible before it.

## Recovery flags (PROG)

- **PROG-050** [H] When the user misses rep targets by 2 or more reps on most sets of a muscle in 2 sessions running, the app must flag underperformance for that muscle.
- **PROG-051** [H] An underperformance flag must recommend easier training: hold sets, and repeat the weight instead of increasing it. The user may ignore it.
- **PROG-052** [H][I] Underperformance in 2 or more muscle groups in the same week must recommend a recovery step:
  - half-session for the rest of the week;
  - early deload;
  - during Fat loss only: a diet-break week first, since RP says "it might not be the training, it might be the diet".
- **PROG-053** [H] When logged reps fall outside the exercise's rep range (threshold in TBL-051), the next target weight must move one equipment step toward the range (down if reps were too low, up if too high), with a note saying why.
- **PROG-054** [H] When pump and soreness are both near zero for most muscles for 2 or more weeks, the app must recommend an active rest (MESO-064).
- **PROG-055** [M][G] When training fatigue is high and the user chooses to cut volume instead of taking a break, each affected muscle's sets must drop by one third (or by half if "Too much" was answered twice running). Then 1 set per muscle per week is added back until feedback finds the new ceiling.

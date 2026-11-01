# 14. Defaults and tables

Every tunable number the requirements use. Values marked [G] are first guesses. They should live in server config so they can change without an app release.

## TBL-001 Body-fat buckets [D]

| Sex | Buckets | Value used for lean mass |
|---|---|---|
| Female | under 20%, 20-27%, 27-35%, over 35% | 17, 23.5, 31, 38 |
| Male | under 15%, 15-22%, 22-30%, over 30% [G, except 22-30% seen in Tom's capture] | 12, 18.5, 26, 33 |

## TBL-010 Phases [D][M]

| Phase | Rate (% bodyweight per week) | Presets | Length allowed | Recommended |
|---|---|---|---|---|
| Fat loss | 0.25 to 1.0 | Highest success 0.75 [G] (RP's 6-month guide uses 0.75), Slow & steady 0.5, Custom | 2 to 16 wk | 6 to 12 wk. Under 6 weeks, the app suggests a Mini-cut instead. Over 12 shows a warning |
| Muscle gain | 0.125 to 0.5 | Recommended by experience: beginner 0.5, intermediate 0.375, advanced 0.25 [G]; Custom | 4 to 16 wk | 8 to 16 wk (book: 6 to 16) |
| Maintenance | 0, or −0.25 to +0.25 | Hold | 2 wk to no end | After a cut: 2/3 to 1x the cut length |
| Mini-cut | 0.75 to 1.0 | Default 1.0 | 2 to 6 wk | Gain:mini-cut length at least 2:1 |

Also: a single fat-loss phase should not plan to lose more than 10% of starting bodyweight. If it would, the app warns at setup. [M] *Renaissance Diet 2.0*.

## TBL-012 Training defaults by phase [M][I][G]

| | Fat loss | Mini-cut | Maintenance (resensitization) | Maintenance (balanced) | Muscle gain |
|---|---|---|---|---|---|
| Priority preset | All Emphasize | All Emphasize | All Maintain | All Grow | Up to 2 Emphasize, rest Grow or Maintain |
| Template fit | Full body | Full body | Full body, 2 to 3 days | Any | Specialisation |
| Start volume (sets/wk vs MEV) | +2 | 0 | MV | 0 | −1 |
| Ceiling (vs MRV) | −2 | MEV +2 (not MRV based) | MV | 0 | +2 |
| Max sets added per session per week | 1 | 1 | 0 | 2 | 2 |
| Weekly load step | 1%, smallest plate | 1% | n/a, hold loads | 2% | 2% |
| Copy load rise over old week 1 (MESO-061) | 2.5% (1 to 1.25% advanced) | 2.5% (1 to 1.25% advanced) | 0, hold loads | 5% (2 to 2.5% advanced) | 5% (2 to 2.5% advanced) |
| Rep-range bias | 10 to 20 and 20 to 30 | 10 to 20 | 5 to 10 | Mixed | Mixed, more 5 to 10 |
| Minimum frequency per muscle | 2x/wk | 2x/wk | 1x/wk | 2x/wk | 2x/wk |
| Deload calories | Maintenance | Maintenance | Maintenance | Maintenance | Maintenance |

## TBL-013 Body-fat estimate during muscle gain [G]

Estimated body fat = (start fat mass + 0.5 × weight gained) ÷ current trend weight. Start fat mass comes from the TBL-001 bucket value. The estimate is reset whenever the user picks a new body-fat bucket in Settings.

## TBL-020 Mesocycle length by experience [H][G]

Weeks include the deload week.

| Experience | Default | Allowed |
|---|---|---|
| Beginner | 6 | 5 to 8 |
| Intermediate | 5 | 5 to 8 |
| Advanced | 5 | 4 to 7 |
| Resensitization mesocycle (PH-036), any level | 4 | 3 to 4 |

## TBL-021 Volume landmarks, sets per muscle per week [M][G]

Approximate values from RP's public landmark articles. The landmark PDF in Tom's library gives the method but leaves the per-muscle table blank, so check these against RP's current published figures before launch. Intermediate defaults. Beginners: MEV −2, MRV −4. Advanced: MEV +2.

| Muscle | MV | MEV | MRV |
|---|---|---|---|
| Chest | 8 | 10 | 22 |
| Back | 8 | 10 | 25 |
| Quads | 6 | 8 | 20 |
| Hamstrings | 4 | 6 | 20 |
| Glutes | 0 | 0 | 16 |
| Side delts | 0 | 8 | 26 |
| Rear delts | 0 | 6 | 26 |
| Front delts | 0 | 0 | 12 |
| Biceps | 5 | 8 | 26 |
| Triceps | 4 | 6 | 18 |
| Calves | 6 | 8 | 20 |
| Abs | 0 | 0 | 25 |
| Traps | 0 | 0 | 26 |
| Forearms | 0 | 0 | 25 |

Floors, applied after experience and phase offsets:

- Starting volume is never below MV and never below 0.
- Any muscle the mesocycle trains directly gets at least 2 sets per session, including at Maintain and in resensitization. This covers muscles with MV or MEV of 0 (they get enough work from other lifts) whenever the template gives them their own slot.

## TBL-022 Feedback scores [H][G]

See PROG-010. The table lives there.

## TBL-023 Target RIR by week [H][G]

| Training weeks (before deload) | RIR by week |
|---|---|
| 2 (resensitization only) | 3, 2. No ramp to failure |
| 3 | 3, 1, 0 |
| 4 | 3, 2, 1, 0 |
| 5 | 3, 2, 2, 1, 0 |
| 6 | 3, 3, 2, 2, 1, 0 |
| 7 | 3, 3, 2, 2, 1, 1, 0 |

## TBL-030 Activity factor from planned steps [D][G]

| Daily steps | Factor |
|---|---|
| under 5,000 | 1.20 |
| 5,000 to 7,999 | 1.30 |
| 8,000 to 9,999 | 1.375 |
| 10,000 to 12,999 | 1.45 |
| 13,000 or more | 1.55 |

## TBL-031 Session intensity from planned working sets [I][G]

| Working sets | Intensity |
|---|---|
| Deload week, any | Light |
| under 10 | Light |
| 10 to 19 | Moderate |
| 20 or more, or estimated length over 75 min | Hard |

## TBL-032 Carb sanity bounds by day type [M]

Day carbs come from NUT-014. These bounds only raise a warning in the weekly review when a day's carbs fall outside them for 2 weeks running, which usually means the fat or protein setting is crowding carbs out.

| Day type | Carbs, g per lb lean mass |
|---|---|
| Rest | 0.5 to 1.0 |
| Light, Moderate, Hard | 1.5 to 3.0 |

## TBL-033 Carbs per meal on training days [M]

*Renaissance Diet 2.0*, Table 10.8, for one workout a day. The "rest" share is split evenly over the meals not named.

| Meals per day | Pre-workout | Post-workout | Bedtime | Rest |
|---|---|---|---|---|
| 2 [G] | 40% | 60% | n/a | n/a |
| 3 [G] | 30% | 45% | 25% | n/a |
| 4 | 25% | 35% | 25% | 15% over 1 meal |
| 5 | 20% | 30% | 20% | 30% over 2 meals |
| 6 | 18% | 25% | 18% | 39% over 3 meals |

When the workout is the last thing before bed, the post-workout meal and the bedtime meal are the same meal and take both shares. With an intra-workout shake (SCH-043), the shake takes 10 points from the post-workout share.

## TBL-040 Load steps by equipment [H][G]

| Load type | kg | lb |
|---|---|---|
| Barbell, smith machine | 2.5 (1.25 with micro plates) | 5 (2.5 with micro plates) |
| Dumbbell | 2 | 5 |
| Machine, cable | 2.5 | 5 |
| Bodyweight | reps only | reps only |
| Bodyweight plus load | 1.25 | 2.5 |

Users must be able to set the step for any exercise (for example a cable stack in 4.5 kg steps) and turn on micro plates in Settings. [G]

## TBL-050 On-target band for the weekly review [G]

Fat loss, Mini-cut and Muscle gain: a week counts as on target when the measured rate is within ±0.15% bodyweight per week of the target rate, or within ±25% of it, whichever is wider.

Maintenance does not use a rate band. It uses the corridor in REV-029: trend weight within ±1% of the trend weight at the start of the phase.

## TBL-051 Review weights and fatigue thresholds [G]

| Item | Value |
|---|---|
| REV-021 expenditure average | Last 3 eligible weeks weighted 50%, 30%, 20%, newest first |
| Training fatigue high (REV-041) | 2 or more muscles flagged underperforming in the week, or more than one third of the week's soreness and volume answers are "I'm still sore!" or "Too much" |
| Diet fatigue high (REV-040) | 6 or more deficit weeks since the last diet break, or a stall (REV-027), or logged days down by 2 or more against the 3-week average |
| Out of rep range (PROG-053) | Logged reps outside the exercise's range on most sets for 2 sessions running |

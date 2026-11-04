# 11. Progress and weigh-ins

## Weigh-ins (WT)

- **WT-001** [D] The weigh-in schedule must be days of the week with a time each, at least 2 days a week. The time sets the reminder only.
- **WT-002** [D] Users must be able to log a weigh-in from Today, the Weigh-ins screen, or the reminder notification.
- **WT-003** [D][B] Weigh-ins posted by the Shortcuts bridge (INT-001) must import with their time and the source `shortcut`. *Bodybuilder: a PWA cannot read Apple Health or Health Connect directly.*
- **WT-004** [D] The Weigh-ins screen (More menu) must list every weigh-in by date, mark missed scheduled weigh-ins in orange, and allow editing and deleting. The list loads 90 days at a time, newest first, and Show older reaches every earlier day.
- **WT-005** [D] Help text must say to weigh first thing in the morning, after the bathroom, in minimal clothing, and that single readings bounce around and only the trend matters.
- **WT-006** [D] Users must be able to log weigh-ins on unscheduled days. Extra readings improve the trend.
- **WT-007** [D] The weigh-in schedule must allow several entries, each a set of weekdays with a time. The entry sheet has Select all days and Delete schedule. Delete is blocked when it would leave fewer than 2 weigh-in days a week.

## Steps and waist (WT)

From mesoprep. rp-clone read steps only from Apple Health.

- **WT-020** [B] Users must be able to log a day's step count by hand, from Today (the step row, SCH-011) and the Weigh-ins screen. Today offers yesterday's steps by default, since the day's count isn't final until it ends. A typed value (source `manual`) is never overwritten by the Shortcuts bridge; a bridge value (source `shortcut`) is replaced by a newer bridge value for the same day.
- **WT-021** [B] A weigh-in may carry an optional waist measurement (cm, shown in the height unit's family: cm or in). Waist has its own line on the Progress chart once 4 readings exist. It never changes a target.

## Trend weight (WT)

- **WT-010** [D][G] Trend weight must be an exponentially smoothed average of daily weights (smoothing factor 0.1 per day), filling missing days by holding the last value.
- **WT-011** [D] Trend weight must be the weight used everywhere a "current weight" is needed: rate, BMI checks, protein, bodyweight exercise loads (EX-006). Before any weigh-in, the profile's weight stands in. One exception, decided by Tom (2026-10-15): the eligibility recheck screens with the profile's stored weight; its later steps use the trend.
- **WT-012** [D] Progress charts must need at least 4 weigh-ins.

## Progress tab (ANA)

- **ANA-001** [D] The Progress tab must show a chart of daily weights and trend weight against the phase's goal range, for the current phase or the whole plan.
- **ANA-002** [D] A summary must show start weight, change so far, and goal weight.
- **ANA-003** [D] A "This week" card must show calorie target against consumed so far, with an Edit schedule link.
- **ANA-004** [D] The weekly review card must appear here when a review is available.
- **ANA-005** [D] Step progress must show the step target against actual steps, when step data is available.
- **ANA-006** [D] Phase history must list past phases with dates, type, weight change and average rate.
- **ANA-007** [H] Mesocycle history must list past mesocycles with dates, phase, and average weekly sets per muscle per week.
- **ANA-008** [I] Each past phase in history must show the mesocycles run during it, side by side with the weight change.
- **ANA-009** [H] Per-exercise history must show every session's sets with weight and reps, with the best estimated 1RM of each session marked.
- **ANA-010** [D] Users must be able to share a progress image (weight chart, phase summary) to other apps.
- **ANA-011** [D] Adherence must show per day as a green dot on the week strip, and per week in the review.
- **ANA-012** [B] Touching or dragging across the weight chart, hovering it with a mouse, or pressing the arrow keys on it must pick the nearest weigh-in day and read it out above the chart: the date, that day's weigh-in, trend weight and goal range, and the waist when one was measured. The server words each readout. Before a pick, the readout shows the latest weigh-in. The chart is a slider for screen readers, its value the readout.

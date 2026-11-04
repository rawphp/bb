# 08. Workouts and feedback

## Workout screen (WO)

- **WO-001** [H] The workout screen must show the session title (week, day, label, date), then each exercise in order with its sets.
- **WO-002** [H] Each exercise header must show name, equipment, recommended rep range, any pinned note, a history button and a menu.
- **WO-003** [H] Each set row must show a menu, a weight field, a reps field, and a Log checkbox.
- **WO-004** [H] From week 2 on, weight and reps must be pre-filled with targets (PROG-020). A target icon shows while the planned target is in use. It disappears when the user changes the weight.
- **WO-005** [H] Changing the weight must recalculate the target reps for that set (PROG-024).
- **WO-006** [H] In week 1 of a mesocycle with no carried loads (a new template, the builder, or a new exercise), weights must be empty. Copied mesocycles pre-fill week 1 from MESO-061, with rep targets from PROG-026. Reps show the target RIR ("3 RIR"). Weight entered on the first set carries to the next sets and can be overwritten.
- **WO-007** [H] An info icon on reps must explain the target and the RIR for the week.
- **WO-008** [H] The header calendar icon must let the user look back at any earlier week and session of the mesocycle.
- **WO-009** [I] The workout screen must show the post-workout meal's time and carbs at the bottom ("Next: Meal 3 at 7:00 PM · 95 g carbs").
- **WO-010** [I] Finishing a workout must record its actual start and end time and send it to the schedule (SCH-052, SCH-054).
- **WO-011** [G] A finished workout must open on a recap above its exercises: how long it took, the sets logged and the weight lifted (weight × reps over the logged sets), then each exercise's best set (the heaviest, then the most reps) against its best in the same session last week ("+2.5 kg on last week", "+2 reps on last week", "Same as last week"), in a status pill that is good when it went up. An exercise with no logged set last week shows no comparison. Tapping Finish brings the recap into view once the server has the finish; offline it shows after sync. Looking back at a finished session (WO-008) shows its recap too.

## Sets (WO)

- **WO-020** [H] The set menu must offer: Add set below, Skip set, Delete set, and Set type.
- **WO-021** [H] Set types must be Regular, Myorep (M) and Myorep match (MM). Regular has three variants shown in the set menu: Straight, Down and Ascending. For myoreps, the user rests 5 to 15 seconds between mini-sets and logs total reps. For myorep match, the user does as many mini-sets as needed to match the first set's reps.
- **WO-022** [H] The exercise menu must offer: Add set (regular, myorep, myorep match), Swap exercise, Pin note, Add note, Move up, Move down, Remove exercise.
- **WO-023** [H] For dumbbells, the user enters one dumbbell's weight. For one-side-at-a-time exercises, the user starts with the weaker side, matches reps on the other, and logs one side's reps.
- **WO-024** [H] Warm-up sets must not be logged. The workout's first visit to an exercise must show the warm-up guidance: 12 reps at a weight good for 30+, 8 reps at a weight good for about 20, 4 reps at about a 10-rep max, then working sets at 3 RIR.
- **WO-025** [H] Every logged set counts as a working set.

## Session actions (WO)

- **WO-030** [H] There must be no rest timer and no superset set type. Help content must explain resting by feel: breathing back to normal, mentally ready, no supporting-muscle cramp, able to hit at least 3 to 5 reps (30 seconds to 5 minutes). Paired exercises go back to back using notes or reordering.
- **WO-031** [H] The day menu must offer: Add exercise, Reorder exercises, Skip workout, Progression rates, Relabel day.
- **WO-040** [H] Finish workout must be available once every set is logged or skipped. Unanswered feedback must block finishing with the banner "Your feedback is required".
- **WO-050** [H][I] Skip workout must ask for confirmation (it can't be undone), mark the session skipped, and apply SCH-053.
- **WO-051** [H] Missed sessions are handled by SCH-057 and SCH-058.
- **WO-052** [H] Help content must cover training while sick, training alongside a sport, and long workouts (fixes: answer "Pushed my limits" honestly, or lower a muscle's priority).

## Feedback (FB)

- **FB-001** [H] After the last exercise for a muscle group in a session, the app must show one Feedback sheet for that muscle.
- **FB-002** [H] Joint pain must be asked per exercise for each exercise of that muscle in the session: "How did your joints feel during {exercise}?" None / Low pain / Moderate pain / A lot of pain.
- **FB-003** [H] Soreness must be asked per muscle: "How sore did you get in your {muscle} AFTER training them LAST TIME?" Never got sore / Healed a while ago / Healed just on time / I'm still sore!
- **FB-004** [H] Pump must be asked per muscle: "How much of a pump did you get today in your {muscle}?" Low pump / Moderate pump / Amazing pump.
- **FB-005** [H] Volume must be asked per muscle: "How was the volume of work (number of hard sets) for your {muscle}?" Not enough / Just right / Pushed my limits / Too much.
- **FB-006** [H] Each question must have a ? icon explaining it and how answers change future sessions.
- **FB-007** [H] Soreness must not be asked the first time the user trains a muscle in the app. [G] It must also be skipped when the muscle was last trained more than 14 days ago.
- **FB-008** [G] No feedback must be asked during deload weeks.
- **FB-009** [H] Users must be able to change feedback for a session until the next session for that muscle starts.
- **FB-010** [I] Feedback answers must be available to the weekly review (REV-040).

<?php

/*
 * Original mesocycle templates (MESO-010..012, MESO-015, PH-036). Draft content for review.
 *
 * A slot line is "Exercise Name|sets". The name must match a library exercise exactly; the muscle slot is the
 * exercise's muscle group. Lines run in training order: compounds first, then isolations (MESO-046).
 *
 * A day maps its label to its lines; the label order is the day order. Priorities are derived by the seeder:
 * `emphasis` muscles emphasize, other trained muscles grow, untrained muscles maintain, and `maintain_all`
 * holds every muscle at maintain (resensitization, PH-036).
 *
 * Cut-fit templates use only high or medium stimulus-to-fatigue exercises (MESO-016).
 */

// Day blueprints, reused across templates below.
$day = [
    // Lean full body: high and medium stimulus-to-fatigue only, safe for fat loss.
    'lean1' => ['Hack Squat|3', 'Incline Dumbbell Bench Press|3', 'Chest-Supported Dumbbell Row|3', 'Lying Leg Curl|3', 'Dumbbell Lateral Raise|3', 'Cable Curl|3', 'Standing Calf Raise|3'],
    'lean2' => ['Romanian Deadlift|3', 'Machine Chest Press|3', 'Lat Pulldown|3', 'Leg Extension|2', 'Cable Lateral Raise|3', 'Triceps Pushdown|2', 'Incline Dumbbell Curl|2', 'Cable Crunch|2'],
    'lean3' => ['Leg Press|3', 'Dumbbell Bench Press|3', 'Seated Cable Row|3', 'Barbell Hip Thrust|3', 'Dumbbell Lateral Raise|3', 'Reverse Pec Deck|2', 'Overhead Cable Triceps Extension|2', 'Seated Calf Raise|3'],
    'lean2a' => ['Hack Squat|4', 'Incline Dumbbell Bench Press|4', 'Chest-Supported Dumbbell Row|4', 'Lying Leg Curl|3', 'Dumbbell Lateral Raise|3', 'Cable Curl|3', 'Overhead Cable Triceps Extension|3', 'Standing Calf Raise|3'],
    'lean2b' => ['Romanian Deadlift|3', 'Leg Press|3', 'Machine Chest Press|4', 'Lat Pulldown|4', 'Cable Lateral Raise|3', 'Incline Dumbbell Curl|3', 'Triceps Pushdown|3', 'Seated Calf Raise|3'],
    'l4a' => ['Hack Squat|3', 'Incline Dumbbell Bench Press|3', 'Chest-Supported Dumbbell Row|3', 'Dumbbell Lateral Raise|3', 'Cable Curl|3', 'Standing Calf Raise|3'],
    'l4b' => ['Romanian Deadlift|3', 'Machine Chest Press|3', 'Lat Pulldown|3', 'Cable Lateral Raise|2', 'Triceps Pushdown|3', 'Cable Crunch|2'],
    'l4c' => ['Leg Press|3', 'Dumbbell Bench Press|3', 'Seated Cable Row|3', 'Lying Leg Curl|3', 'Reverse Pec Deck|2', 'Incline Dumbbell Curl|2', 'Seated Calf Raise|3'],
    'l4d' => ['Bulgarian Split Squat|3', 'Pull-Up|3', 'Neutral-Grip Dumbbell Press|3', 'Barbell Hip Thrust|3', 'Leg Extension|2', 'Cable Lateral Raise|3', 'Overhead Cable Triceps Extension|2'],
    'l5e' => ['Pendulum Squat|3', 'Chest-Supported T-Bar Row|3', 'Incline Machine Chest Press|3', 'Seated Leg Curl|3', 'Face Pull|2', 'Rope Triceps Pushdown|2', 'Hanging Knee Raise|2'],

    // Machines and cables.
    'mc1' => ['Hack Squat|3', 'Machine Chest Press|3', 'Machine Row|3', 'Seated Leg Curl|3', 'Machine Lateral Raise|3', 'Machine Preacher Curl|3', 'Machine Crunch|2'],
    'mc2' => ['Pendulum Squat|3', 'Incline Machine Chest Press|3', 'Wide-Grip Lat Pulldown|3', 'Machine Hip Thrust|3', 'Cable Lateral Raise|3', 'Machine Triceps Extension|2', 'Seated Calf Raise|3'],
    'mc3' => ['Leg Press|3', 'Hammer Strength Chest Press|3', 'Chest-Supported Machine Row|3', 'Lying Leg Curl|3', 'Reverse Pec Deck|2', 'Cable Curl|2', 'Rope Triceps Pushdown|2', 'Standing Calf Raise|3'],

    // Dumbbells and bodyweight.
    'db1' => ['Goblet Squat|3', 'Dumbbell Bench Press|3', 'One-Arm Dumbbell Row|3', 'Dumbbell Romanian Deadlift|3', 'Dumbbell Lateral Raise|3', 'Hammer Curl|3', 'Dumbbell Calf Raise|3'],
    'db2' => ['Bulgarian Split Squat|3', 'Incline Dumbbell Bench Press|3', 'Chest-Supported Dumbbell Row|3', 'Dumbbell Hip Thrust|3', 'Leaning Dumbbell Lateral Raise|3', 'Overhead Dumbbell Extension|2', 'Seated Dumbbell Calf Raise|3'],
    'db3' => ['Reverse Lunge|3', 'Neutral-Grip Dumbbell Press|3', 'Incline Dumbbell Row|3', 'Dumbbell Romanian Deadlift|3', 'Bent-Over Dumbbell Reverse Fly|2', 'Incline Dumbbell Curl|2', 'Dumbbell Skull Crusher|2'],

    // Barbell-based full body.
    'ess2a' => ['Barbell Back Squat|3', 'Barbell Bench Press|4', 'Barbell Row|4', 'Romanian Deadlift|3', 'Dumbbell Lateral Raise|3', 'EZ-Bar Curl|3', 'Overhead Cable Triceps Extension|3', 'Standing Calf Raise|3'],
    'ess2b' => ['Leg Press|4', 'Incline Dumbbell Bench Press|4', 'Pull-Up|4', 'Lying Leg Curl|3', 'Cable Lateral Raise|3', 'Incline Dumbbell Curl|3', 'Triceps Pushdown|3', 'Seated Calf Raise|3'],
    'ess1' => ['Barbell Back Squat|3', 'Barbell Bench Press|3', 'Barbell Row|3', 'Romanian Deadlift|2', 'Dumbbell Lateral Raise|3', 'EZ-Bar Curl|2', 'Standing Calf Raise|3'],
    'ess2' => ['Leg Press|3', 'Incline Barbell Bench Press|3', 'Pull-Up|3', 'Lying Leg Curl|3', 'Cable Lateral Raise|3', 'Skull Crusher|3', 'Seated Calf Raise|3'],
    'ess3' => ['Barbell Front Squat|3', 'Dumbbell Bench Press|3', 'Chest-Supported T-Bar Row|3', 'Seated Dumbbell Shoulder Press|2', 'Seated Leg Curl|2', 'Reverse Pec Deck|3', 'Incline Dumbbell Curl|3', 'Overhead Cable Triceps Extension|3'],
    'e4a' => ['Barbell Back Squat|3', 'Barbell Bench Press|3', 'Barbell Row|3', 'Dumbbell Lateral Raise|3', 'EZ-Bar Curl|2', 'Standing Calf Raise|3'],
    'e4b' => ['Romanian Deadlift|3', 'Incline Dumbbell Bench Press|3', 'Pull-Up|3', 'Cable Lateral Raise|2', 'Triceps Pushdown|3', 'Hanging Leg Raise|2'],
    'e4c' => ['Hack Squat|3', 'Machine Chest Press|3', 'Chest-Supported Dumbbell Row|3', 'Lying Leg Curl|3', 'Reverse Pec Deck|2', 'Incline Dumbbell Curl|3', 'Seated Calf Raise|3'],
    'e4d' => ['Bulgarian Split Squat|3', 'Dumbbell Bench Press|3', 'Wide-Grip Lat Pulldown|3', 'Barbell Hip Thrust|3', 'Seated Leg Curl|3', 'Cable Lateral Raise|3', 'Overhead Cable Triceps Extension|3'],
    'e5e' => ['Barbell Front Squat|3', 'Dumbbell Bench Press|3', 'T-Bar Row|3', 'Romanian Deadlift|3', 'Machine Lateral Raise|3', 'Hammer Curl|2', 'Cable Crunch|2'],

    // Lower-body weighted full body (glutes and legs lead).
    'lw1' => ['Barbell Hip Thrust|4', 'Leg Press|3', 'Lat Pulldown|3', 'Incline Dumbbell Bench Press|3', 'Lying Leg Curl|3', 'Cable Lateral Raise|2', 'Cable Curl|3', 'Standing Calf Raise|3'],
    'lw2' => ['Romanian Deadlift|3', 'Hack Squat|3', 'Chest-Supported Dumbbell Row|3', 'Machine Chest Press|2', 'Seated Leg Curl|3', 'Triceps Pushdown|2', 'Cable Hip Abduction|2', 'Standing Calf Raise|3'],
    'lw3' => ['Bulgarian Split Squat|3', 'Smith Machine Hip Thrust|3', 'Seated Cable Row|3', 'Push-Up|3', 'Dumbbell Lateral Raise|3', 'Cable Curl|2', 'Overhead Cable Triceps Extension|2', 'Seated Calf Raise|3'],

    // Upper-body weighted full body.
    'uw1' => ['Barbell Bench Press|4', 'Barbell Row|3', 'Barbell Back Squat|3', 'Seated Dumbbell Shoulder Press|2', 'Dumbbell Lateral Raise|3', 'EZ-Bar Curl|3', 'Skull Crusher|3', 'Standing Calf Raise|3'],
    'uw2' => ['Incline Barbell Bench Press|4', 'Pull-Up|4', 'Leg Press|3', 'Romanian Deadlift|3', 'Cable Lateral Raise|3', 'Preacher Curl|2', 'Rope Triceps Pushdown|3', 'Seated Calf Raise|3'],
    'uw3' => ['Dumbbell Bench Press|3', 'Chest-Supported T-Bar Row|3', 'Hack Squat|3', 'Wide-Grip Lat Pulldown|3', 'Lying Leg Curl|2', 'Reverse Pec Deck|3', 'Incline Dumbbell Curl|3', 'Overhead Cable Triceps Extension|3'],

    // Upper/lower.
    'upA' => ['Barbell Bench Press|4', 'Barbell Row|4', 'Seated Dumbbell Shoulder Press|2', 'Dumbbell Lateral Raise|3', 'EZ-Bar Curl|3', 'Skull Crusher|3'],
    'upB' => ['Pull-Up|4', 'Incline Dumbbell Bench Press|4', 'Seated Cable Row|3', 'Cable Lateral Raise|3', 'Reverse Pec Deck|3', 'Incline Dumbbell Curl|3', 'Overhead Cable Triceps Extension|3'],
    'upC' => ['Incline Machine Chest Press|3', 'Wide-Grip Lat Pulldown|3', 'Machine Shoulder Press|2', 'Leaning Dumbbell Lateral Raise|3', 'Face Pull|3', 'Preacher Curl|3', 'Rope Triceps Pushdown|3'],
    'loA' => ['Barbell Back Squat|3', 'Romanian Deadlift|3', 'Leg Extension|2', 'Lying Leg Curl|3', 'Standing Calf Raise|3', 'Cable Crunch|3'],
    'loB' => ['Hack Squat|3', 'Barbell Hip Thrust|3', 'Seated Leg Curl|3', 'Leg Extension|2', 'Seated Calf Raise|3', 'Hanging Knee Raise|2'],
    'loC' => ['Pendulum Squat|3', 'Stiff-Leg Deadlift|3', 'Walking Lunge|2', 'Lying Leg Curl|3', 'Leg Press Calf Raise|3', 'Cable Crunch|3', 'Machine Hip Abduction|2'],
    'umA' => ['Incline Machine Chest Press|4', 'Machine Row|4', 'Machine Shoulder Press|2', 'Machine Lateral Raise|3', 'Machine Preacher Curl|3', 'Machine Triceps Extension|3'],
    'umB' => ['Wide-Grip Lat Pulldown|4', 'Hammer Strength Chest Press|4', 'Chest-Supported Machine Row|3', 'Pec Deck Fly|3', 'Cable Lateral Raise|3', 'Reverse Pec Deck|3', 'Cable Curl|3', 'Rope Triceps Pushdown|3'],
    'lmA' => ['Leg Press|4', 'Smith Machine Romanian Deadlift|3', 'Leg Extension|3', 'Seated Leg Curl|3', 'Standing Calf Raise|3', 'Machine Crunch|3'],
    'lmB' => ['Pendulum Squat|3', 'Machine Hip Thrust|3', 'Lying Leg Curl|3', 'Single-Leg Extension|3', 'Seated Calf Raise|3', 'Cable Crunch|2'],

    // Push / pull / legs.
    'pushA' => ['Barbell Bench Press|3', 'Incline Dumbbell Bench Press|3', 'Seated Dumbbell Shoulder Press|3', 'Cable Fly|2', 'Dumbbell Lateral Raise|4', 'Triceps Pushdown|3', 'Overhead Cable Triceps Extension|3'],
    'pullA' => ['Pull-Up|3', 'Barbell Row|3', 'Seated Cable Row|3', 'Reverse Pec Deck|3', 'EZ-Bar Curl|3', 'Hammer Curl|2', 'Dumbbell Shrug|2'],
    'legsA' => ['Barbell Back Squat|3', 'Romanian Deadlift|3', 'Leg Press|3', 'Leg Extension|2', 'Lying Leg Curl|3', 'Standing Calf Raise|4', 'Cable Crunch|3'],
    'pushB' => ['Dumbbell Bench Press|3', 'Incline Machine Chest Press|3', 'Machine Shoulder Press|2', 'Pec Deck Fly|3', 'Cable Lateral Raise|3', 'Skull Crusher|3', 'Single-Arm Cable Pushdown|2'],
    'pullB' => ['Lat Pulldown|3', 'Chest-Supported T-Bar Row|3', 'One-Arm Dumbbell Row|3', 'Face Pull|3', 'Incline Dumbbell Curl|3', 'Cable Curl|2', 'Machine Shrug|2'],
    'legsB' => ['Hack Squat|3', 'Barbell Hip Thrust|3', 'Bulgarian Split Squat|3', 'Seated Leg Curl|3', 'Leg Extension|3', 'Seated Calf Raise|4', 'Hanging Knee Raise|3'],

    // Resensitization (PH-036): every muscle at maintain, near MV; at least 2 sets a slot, each muscle in 1 to 2 sessions.
    'rs2a' => ['Hack Squat|3', 'Incline Dumbbell Bench Press|4', 'Chest-Supported Dumbbell Row|4', 'Lying Leg Curl|2', 'Dumbbell Lateral Raise|2', 'Cable Curl|3', 'Overhead Cable Triceps Extension|2', 'Standing Calf Raise|3'],
    'rs2b' => ['Leg Press|3', 'Machine Chest Press|4', 'Lat Pulldown|4', 'Romanian Deadlift|2', 'Triceps Pushdown|2', 'Cable Lateral Raise|2', 'Incline Dumbbell Curl|2', 'Seated Calf Raise|3'],
    'rs3a' => ['Hack Squat|3', 'Incline Dumbbell Bench Press|4', 'Chest-Supported Dumbbell Row|4', 'Dumbbell Lateral Raise|2', 'Cable Curl|3', 'Standing Calf Raise|3'],
    'rs3b' => ['Romanian Deadlift|2', 'Leg Press|3', 'Lat Pulldown|4', 'Triceps Pushdown|2', 'Incline Dumbbell Curl|2', 'Seated Calf Raise|3'],
    'rs3c' => ['Dumbbell Bench Press|4', 'Barbell Hip Thrust|2', 'Lying Leg Curl|2', 'Cable Lateral Raise|2', 'Overhead Cable Triceps Extension|2', 'Cable Crunch|2'],
    'rm3a' => ['Leg Press|3', 'Machine Chest Press|4', 'Machine Row|4', 'Machine Lateral Raise|2', 'Machine Preacher Curl|3', 'Seated Calf Raise|3'],
    'rm3b' => ['Hack Squat|3', 'Lat Pulldown|4', 'Machine Shoulder Press|2', 'Seated Leg Curl|2', 'Machine Triceps Extension|2', 'Standing Calf Raise|3', 'Machine Crunch|2'],
    'rm3c' => ['Incline Machine Chest Press|4', 'Machine Hip Thrust|2', 'Lying Leg Curl|2', 'Cable Lateral Raise|2', 'Reverse Pec Deck|2', 'Rope Triceps Pushdown|2', 'Cable Curl|2'],
];

/**
 * Change a day blueprint: drop exercises by name, put compounds at the front, add isolations at the end.
 *
 * @param  list<string>  $lines
 * @param  list<string>  $add
 * @param  list<string>  $front
 * @param  list<string>  $drop
 * @return list<string>
 */
$mod = fn (array $lines, array $add = [], array $front = [], array $drop = []): array => [
    ...$front,
    ...array_values(array_filter($lines, fn (string $line) => ! in_array(explode('|', $line)[0], $drop, true))),
    ...$add,
];

/**
 * Change set counts by exercise name, e.g. ['Pull-Up' => 2].
 *
 * @param  list<string>  $lines
 * @param  array<string, int>  $sets
 * @return list<string>
 */
$tune = fn (array $lines, array $sets): array => array_map(function (string $line) use ($sets): string {
    $name = explode('|', $line)[0];

    return isset($sets[$name]) ? $name.'|'.$sets[$name] : $line;
}, $lines);

/** Label days "Prefix A", "Prefix B", ... in order. */
$lettered = function (string $prefix, array ...$days): array {
    $labelled = [];

    foreach ($days as $index => $lines) {
        $labelled[$prefix.' '.chr(65 + $index)] = $lines;
    }

    return $labelled;
};

$template = fn (string $name, string $split, string $sex, array $phaseFit, array $days, array $emphasis = [], bool $maintainAll = false): array => [
    'name' => $name,
    'split' => $split,
    'sex' => $sex,
    'phase_fit' => $phaseFit,
    'days' => $days,
    'emphasis' => $emphasis,
    'maintain_all' => $maintainAll,
];

$fullBody = 'full_body';
$upperLower = 'upper_lower';
$pushPullLegs = 'push_pull_legs';
$specialisation = 'specialisation';

$ul4 = fn (array $a, array $b, array $c, array $d): array => ['Upper A' => $a, 'Lower A' => $b, 'Upper B' => $c, 'Lower B' => $d];
$ppl6 = fn (array $pushA, array $pullA, array $legsA, array $pushB, array $pullB, array $legsB): array => [
    'Push A' => $pushA, 'Pull A' => $pullA, 'Legs A' => $legsA, 'Push B' => $pushB, 'Pull B' => $pullB, 'Legs B' => $legsB,
];
$ul5 = fn (array $upA, array $loA, array $upB, array $loB, array $upC): array => [
    'Upper A' => $upA, 'Lower A' => $loA, 'Upper B' => $upB, 'Lower B' => $loB, 'Upper C' => $upC,
];
$ul6 = fn (array $upA, array $loA, array $upB, array $loB, array $upC, array $loC): array => [
    'Upper A' => $upA, 'Lower A' => $loA, 'Upper B' => $upB, 'Lower B' => $loB, 'Upper C' => $upC, 'Lower C' => $loC,
];
$lowerLed5 = fn (array $loA, array $upA, array $loB, array $upB, array $loC): array => [
    'Lower A' => $loA, 'Upper A' => $upA, 'Lower B' => $loB, 'Upper B' => $upB, 'Lower C' => $loC,
];

return [
    // Full body, 2 to 5 days.
    $template('Full Body 2-Day Essentials', $fullBody, 'any', ['gain', 'maintain'], $lettered('Full Body', $day['ess2a'], $day['ess2b'])),
    $template('Full Body 2-Day Lean', $fullBody, 'any', ['cut', 'maintain'], $lettered('Full Body', $day['lean2a'], $day['lean2b'])),
    $template('Full Body 3-Day Essentials', $fullBody, 'any', ['gain', 'maintain'], $lettered('Full Body', $day['ess1'], $day['ess2'], $day['ess3'])),
    $template('Full Body 3-Day Lean', $fullBody, 'any', ['cut', 'maintain'], $lettered('Full Body', $day['lean1'], $day['lean2'], $day['lean3'])),
    $template('Full Body 3-Day Machines and Cables', $fullBody, 'any', ['cut', 'maintain'], $lettered('Full Body', $day['mc1'], $day['mc2'], $day['mc3'])),
    $template('Full Body 3-Day Dumbbells', $fullBody, 'any', ['cut', 'gain', 'maintain'], $lettered('Full Body', $day['db1'], $day['db2'], $day['db3'])),
    $template('Full Body 3-Day Lower-Body Bias', $fullBody, 'female', ['cut', 'gain', 'maintain'], $lettered('Full Body', $day['lw1'], $day['lw2'], $day['lw3'])),
    $template('Full Body 3-Day Upper-Body Bias', $fullBody, 'male', ['gain', 'maintain'], $lettered('Full Body', $day['uw1'], $day['uw2'], $day['uw3'])),
    $template('Full Body 4-Day Essentials', $fullBody, 'any', ['gain', 'maintain'], $lettered('Full Body', $day['e4a'], $day['e4b'], $day['e4c'], $day['e4d'])),
    $template('Full Body 4-Day Lean', $fullBody, 'any', ['cut', 'maintain'], $lettered('Full Body', $day['l4a'], $day['l4b'], $day['l4c'], $day['l4d'])),
    $template('Full Body 4-Day Lower-Body Bias', $fullBody, 'female', ['cut', 'gain', 'maintain'], $lettered(
        'Full Body',
        $mod($day['l4a'], add: ['Cable Kickback|3']),
        $mod($day['l4b'], add: ['Cable Hip Abduction|3']),
        $mod($day['l4c'], front: ['Barbell Hip Thrust|3']),
        $mod($day['l4d'], add: ['Machine Hip Abduction|3']),
    )),
    $template('Full Body 4-Day Upper-Body Bias', $fullBody, 'male', ['gain', 'maintain'], $lettered(
        'Full Body',
        $mod($day['e4a'], add: ['Skull Crusher|2']),
        $mod($day['e4b'], add: ['Preacher Curl|3']),
        $mod($day['e4c'], add: ['Rope Triceps Pushdown|3']),
        $mod($day['e4d'], add: ['Hammer Curl|2']),
    )),
    $template('Full Body 5-Day Lean', $fullBody, 'any', ['cut', 'maintain'], $lettered('Full Body', $day['l4a'], $day['l4b'], $day['l4c'], $day['l4d'], $day['l5e'])),
    $template('Full Body 5-Day Essentials', $fullBody, 'any', ['gain', 'maintain'], $lettered('Full Body', $day['e4a'], $day['e4b'], $day['e4c'], $day['e4d'], $day['e5e'])),

    // Resensitization after fat loss (PH-036).
    $template('Resensitization 2-Day Full Body', $fullBody, 'any', ['maintain'], $lettered('Full Body', $day['rs2a'], $day['rs2b']), maintainAll: true),
    $template('Resensitization 3-Day Full Body', $fullBody, 'any', ['maintain'], $lettered('Full Body', $day['rs3a'], $day['rs3b'], $day['rs3c']), maintainAll: true),
    $template('Resensitization 3-Day Machines', $fullBody, 'any', ['maintain'], $lettered('Full Body', $day['rm3a'], $day['rm3b'], $day['rm3c']), maintainAll: true),

    // Upper/lower.
    $template('Upper/Lower 4-Day Essentials', $upperLower, 'any', ['gain', 'maintain'], $ul4($day['upA'], $day['loA'], $day['upB'], $day['loB'])),
    $template('Upper/Lower 4-Day Machines', $upperLower, 'any', ['gain', 'maintain'], $ul4($day['umA'], $day['lmA'], $day['umB'], $day['lmB'])),
    $template('Upper/Lower 4-Day Lower-Body Bias', $upperLower, 'female', ['gain', 'maintain'], $ul4(
        $mod($day['upA'], drop: ['Seated Dumbbell Shoulder Press']),
        $mod($day['loA'], add: ['Cable Kickback|2'], front: ['Barbell Hip Thrust|3']),
        $day['upB'],
        $mod($day['loB'], add: ['Cable Hip Abduction|3'], front: ['Bulgarian Split Squat|3']),
    )),
    $template('Upper/Lower 4-Day Upper-Body Bias', $upperLower, 'male', ['gain', 'maintain'], $ul4(
        $mod($day['upA'], add: ['Rope Triceps Pushdown|3']),
        $mod($day['loA'], drop: ['Cable Crunch']),
        $mod($day['upB'], add: ['Hammer Curl|3']),
        $mod($day['loB'], drop: ['Hanging Knee Raise']),
    )),
    $template('Upper/Lower 5-Day Essentials', $upperLower, 'any', ['gain', 'maintain'], $ul5($day['upA'], $day['loA'], $day['upB'], $day['loB'], $day['upC'])),
    $template('Upper/Lower 6-Day Essentials', $upperLower, 'any', ['gain', 'maintain'], $ul6($day['upA'], $day['loA'], $day['upB'], $day['loB'], $day['upC'], $day['loC'])),

    // Push / pull / legs.
    $template('Push/Pull/Legs 5-Day Hybrid', $pushPullLegs, 'any', ['gain', 'maintain'], [
        'Push' => $day['pushA'], 'Pull' => $day['pullA'], 'Legs' => $day['legsA'], 'Upper' => $day['upB'], 'Lower' => $day['loB'],
    ]),
    $template('Push/Pull/Legs 6-Day Essentials', $pushPullLegs, 'any', ['gain', 'maintain'], $ppl6($day['pushA'], $day['pullA'], $day['legsA'], $day['pushB'], $day['pullB'], $day['legsB'])),
    $template('Push/Pull/Legs 6-Day Hypertrophy', $pushPullLegs, 'any', ['gain'], $ppl6(
        $mod($day['pushA'], add: ['Machine Lateral Raise|3']),
        $mod($day['pullA'], add: ['Face Pull|2']),
        $mod($day['legsA'], add: ['Seated Calf Raise|3']),
        $mod($day['pushB'], add: ['Cable Fly|2']),
        $mod($day['pullB'], add: ['Straight-Arm Cable Pulldown|3']),
        $mod($day['legsB'], add: ['Leg Press Calf Raise|3']),
    )),
    $template('Push/Pull/Legs 6-Day Upper-Body Bias', $pushPullLegs, 'male', ['gain', 'maintain'], $ppl6(
        $mod($day['pushA'], add: ['Rope Triceps Pushdown|3']),
        $mod($day['pullA'], add: ['Straight-Arm Cable Pulldown|3']),
        $mod($day['legsA'], drop: ['Cable Crunch']),
        $mod($day['pushB'], add: ['Cable Fly|2']),
        $mod($day['pullB'], add: ['Preacher Curl|3']),
        $mod($day['legsB'], drop: ['Hanging Knee Raise']),
    )),
    $template('Push/Pull/Legs 6-Day Lower-Body Bias', $pushPullLegs, 'female', ['gain', 'maintain'], $ppl6(
        $mod($day['pushA'], drop: ['Overhead Cable Triceps Extension']),
        $mod($day['pullA'], drop: ['Dumbbell Shrug']),
        $mod($day['legsA'], front: ['Barbell Hip Thrust|3']),
        $mod($day['pushB'], drop: ['Single-Arm Cable Pushdown']),
        $mod($day['pullB'], drop: ['Machine Shrug']),
        $mod($day['legsB'], add: ['Cable Kickback|3', 'Machine Hip Abduction|3'], drop: ['Hanging Knee Raise']),
    )),

    // Specialisations for muscle gain: at most 2 emphasized muscles, trained at least twice a week.
    $template('Chest Specialisation 3-Day', $specialisation, 'any', ['gain'], $lettered(
        'Full Body',
        $mod($day['ess1'], add: ['Cable Fly|3']),
        $mod($day['ess2'], add: ['Cable Fly|3']),
        $mod($day['ess3'], add: ['Pec Deck Fly|3'], drop: ['Seated Dumbbell Shoulder Press']),
    ), ['chest']),
    $template('Glute Specialisation 3-Day', $specialisation, 'female', ['gain'], $lettered(
        'Full Body',
        $day['lw1'],
        $mod($day['lw2'], add: ['Cable Kickback|3'], drop: ['Cable Hip Abduction']),
        $day['lw3'],
    ), ['glutes']),
    $template('Calf Specialisation 3-Day', $specialisation, 'any', ['gain'], $lettered(
        'Full Body',
        $mod($day['ess1'], add: ['Leg Press Calf Raise|3']),
        $mod($day['ess2'], add: ['Standing Calf Raise|3']),
        $mod($day['ess3'], add: ['Seated Calf Raise|3'], drop: ['Seated Dumbbell Shoulder Press']),
    ), ['calves']),
    $template('Arm Specialisation 3-Day', $specialisation, 'any', ['gain'], $lettered(
        'Full Body',
        $mod($day['ess1'], add: ['Skull Crusher|3']),
        $mod($day['ess2'], add: ['Hammer Curl|3']),
        $mod($day['ess3'], add: ['Preacher Curl|3'], drop: ['Reverse Pec Deck']),
    ), ['biceps', 'triceps']),

    $template('Chest Specialisation 4-Day', $specialisation, 'any', ['gain'], $ul4(
        $mod($day['upA'], add: ['Cable Fly|3'], front: ['Machine Chest Press|3']),
        $day['loA'],
        $mod($day['upB'], add: ['Cable Fly|3']),
        $day['loB'],
    ), ['chest']),
    $template('Back Specialisation 4-Day', $specialisation, 'any', ['gain'], $ul4(
        $mod($day['upA'], add: ['Straight-Arm Cable Pulldown|3'], front: ['Wide-Grip Lat Pulldown|3']),
        $day['loA'],
        $mod($day['upB'], front: ['Chest-Supported Machine Row|3']),
        $day['loB'],
    ), ['back']),
    $template('Arm Specialisation 4-Day', $specialisation, 'any', ['gain'], $ul4(
        $mod($day['upA'], add: ['Preacher Curl|3', 'Rope Triceps Pushdown|3']),
        $mod($day['loA'], add: ['Cable Curl|3', 'Triceps Pushdown|3']),
        $mod($day['upB'], add: ['Hammer Curl|3']),
        $mod($day['loB'], add: ['Bayesian Cable Curl|2']),
    ), ['biceps', 'triceps']),
    $template('Shoulder Specialisation 4-Day', $specialisation, 'any', ['gain'], $ul4(
        $mod($day['upA'], add: ['Behind-the-Back Cable Lateral Raise|3']),
        $mod($day['loA'], add: ['Machine Lateral Raise|3', 'Reverse Pec Deck|3']),
        $mod($day['upB'], add: ['Face Pull|3']),
        $mod($day['loB'], add: ['Leaning Dumbbell Lateral Raise|3', 'Cable Reverse Fly|3']),
    ), ['sideDelts', 'rearDelts']),
    $template('Quad Specialisation 4-Day', $specialisation, 'any', ['gain'], $ul4(
        $day['upA'],
        $mod($day['loA'], front: ['Hack Squat|3']),
        $day['upB'],
        $mod($day['loB'], front: ['Leg Press|3']),
    ), ['quads']),
    $template('Glute Specialisation 4-Day', $specialisation, 'female', ['gain'], $ul4(
        $day['upA'],
        $mod($day['loA'], add: ['Cable Kickback|3'], front: ['Barbell Hip Thrust|4']),
        $day['upB'],
        $mod($day['loB'], add: ['Machine Hip Abduction|3']),
    ), ['glutes']),
    $template('Hamstring and Glute Specialisation 4-Day', $specialisation, 'any', ['gain'], $ul4(
        $day['upA'],
        $mod($day['loA'], add: ['Seated Leg Curl|3', 'Cable Kickback|3'], front: ['Barbell Hip Thrust|3'], drop: ['Cable Crunch']),
        $day['upB'],
        $mod($day['loB'], add: ['Glute-Ham Raise|3', 'Cable Hip Abduction|2']),
    ), ['hamstrings', 'glutes']),

    $template('Chest and Back Specialisation 5-Day', $specialisation, 'any', ['gain'], $ul5(
        $mod($day['upA'], add: ['Cable Fly|3', 'Straight-Arm Cable Pulldown|3']),
        $day['loA'],
        $mod($day['upB'], add: ['Pec Deck Fly|3']),
        $day['loB'],
        $mod($day['upC'], add: ['Cable Fly|3']),
    ), ['chest', 'back']),
    $template('Shoulder and Biceps Specialisation 5-Day', $specialisation, 'any', ['gain'], $ul5(
        $mod($day['upA'], add: ['Behind-the-Back Cable Lateral Raise|3', 'Preacher Curl|3']),
        $mod($day['loA'], add: ['Machine Lateral Raise|3']),
        $mod($day['upB'], add: ['Leaning Dumbbell Lateral Raise|3']),
        $day['loB'],
        $mod($day['upC'], add: ['Bayesian Cable Curl|3']),
    ), ['sideDelts', 'biceps']),
    $template('Back and Traps Specialisation 5-Day', $specialisation, 'any', ['gain'], $ul5(
        $mod($day['upA'], add: ['Barbell Shrug|4'], front: ['Wide-Grip Lat Pulldown|3']),
        $day['loA'],
        $mod($day['upB'], add: ['Machine Shrug|4']),
        $day['loB'],
        $mod($day['upC'], add: ['Cable Shrug|4']),
    ), ['back', 'traps']),
    $template('Quad and Glute Specialisation 5-Day', $specialisation, 'female', ['gain'], $lowerLed5(
        $mod($day['loA'], add: ['Cable Kickback|3'], front: ['Hack Squat|3'], drop: ['Cable Crunch']),
        $day['upA'],
        $mod($day['loB'], add: ['Machine Hip Abduction|3'], front: ['Leg Press|3'], drop: ['Leg Extension']),
        $day['upB'],
        $mod($day['loC'], front: ['Barbell Hip Thrust|4'], drop: ['Cable Crunch']),
    ), ['quads', 'glutes']),

    // 6-day specialisations trade volume: the emphasized muscles sit above every other trained muscle.
    $template('Chest and Triceps Specialisation 6-Day', $specialisation, 'any', ['gain'], $ppl6(
        $mod($tune($day['pushA'], ['Triceps Pushdown' => 4, 'Overhead Cable Triceps Extension' => 4]), add: ['Pec Deck Fly|3']),
        $tune($day['pullA'], ['Barbell Row' => 2, 'Seated Cable Row' => 2]),
        $tune($day['legsA'], ['Leg Press' => 2]),
        $mod($day['pushB'], add: ['Overhead Dumbbell Extension|3']),
        $tune($day['pullB'], ['One-Arm Dumbbell Row' => 2]),
        $tune($day['legsB'], ['Bulgarian Split Squat' => 2]),
    ), ['chest', 'triceps']),
    $template('Back and Biceps Specialisation 6-Day', $specialisation, 'any', ['gain'], $ppl6(
        $day['pushA'],
        $mod($tune($day['pullA'], ['EZ-Bar Curl' => 4]), add: ['Straight-Arm Cable Pulldown|3']),
        $tune($day['legsA'], ['Leg Press' => 2]),
        $tune($day['pushB'], ['Pec Deck Fly' => 2, 'Incline Machine Chest Press' => 2]),
        $mod($tune($day['pullB'], ['Incline Dumbbell Curl' => 4, 'Cable Curl' => 3]), add: ['Bayesian Cable Curl|3']),
        $tune($day['legsB'], ['Bulgarian Split Squat' => 2]),
    ), ['back', 'biceps']),
    $template('Delt Specialisation 6-Day', $specialisation, 'any', ['gain'], $ppl6(
        $mod($tune($day['pushA'], ['Dumbbell Lateral Raise' => 5, 'Barbell Bench Press' => 2]), add: ['Machine Lateral Raise|4']),
        $mod($tune($day['pullA'], ['Pull-Up' => 2, 'Barbell Row' => 2, 'Seated Cable Row' => 2, 'Reverse Pec Deck' => 4]), add: ['Face Pull|3']),
        $tune($day['legsA'], ['Leg Press' => 2]),
        $mod($tune($day['pushB'], ['Cable Lateral Raise' => 4, 'Pec Deck Fly' => 2, 'Incline Machine Chest Press' => 2]), add: ['Behind-the-Back Cable Lateral Raise|4']),
        $mod($tune($day['pullB'], ['Chest-Supported T-Bar Row' => 2, 'Face Pull' => 4]), add: ['Cable Reverse Fly|3']),
        $tune($day['legsB'], ['Bulgarian Split Squat' => 2, 'Hack Squat' => 2]),
    ), ['sideDelts', 'rearDelts']),
    $template('Quad and Calf Specialisation 6-Day', $specialisation, 'any', ['gain'], $ppl6(
        $day['pushA'],
        $tune($day['pullA'], ['Seated Cable Row' => 2]),
        $mod($tune($day['legsA'], ['Leg Extension' => 3, 'Standing Calf Raise' => 5]), add: ['Seated Calf Raise|3']),
        $tune($day['pushB'], ['Pec Deck Fly' => 2, 'Dumbbell Bench Press' => 2]),
        $tune($day['pullB'], ['One-Arm Dumbbell Row' => 2, 'Chest-Supported T-Bar Row' => 2]),
        $mod($tune($day['legsB'], ['Leg Extension' => 4, 'Seated Calf Raise' => 5]), add: ['Leg Press Calf Raise|3']),
    ), ['quads', 'calves']),
    $template('Glute Specialisation 6-Day', $specialisation, 'female', ['gain'], $ppl6(
        $mod($day['pushA'], add: ['Cable Hip Abduction|3']),
        $tune($day['pullA'], ['Seated Cable Row' => 2]),
        $mod($day['legsA'], front: ['Barbell Hip Thrust|3']),
        $tune($day['pushB'], ['Pec Deck Fly' => 2]),
        $mod($tune($day['pullB'], ['Chest-Supported T-Bar Row' => 2]), add: ['Machine Hip Abduction|3']),
        $mod($tune($day['legsB'], ['Barbell Hip Thrust' => 4, 'Bulgarian Split Squat' => 2]), add: ['Cable Kickback|3']),
    ), ['glutes']),
    $template('Arm Specialisation 6-Day', $specialisation, 'male', ['gain'], $ppl6(
        $mod($day['pushA'], add: ['Skull Crusher|3']),
        $mod($tune($day['pullA'], ['Seated Cable Row' => 2]), add: ['Preacher Curl|3']),
        $tune($day['legsA'], ['Leg Press' => 2]),
        $mod($tune($day['pushB'], ['Pec Deck Fly' => 2]), add: ['Overhead Dumbbell Extension|3']),
        $mod($tune($day['pullB'], ['Chest-Supported T-Bar Row' => 2]), add: ['Bayesian Cable Curl|3']),
        $day['legsB'],
    ), ['biceps', 'triceps']),
    $template('Chest and Back Specialisation 6-Day', $specialisation, 'any', ['gain'], $ul6(
        $mod($day['upA'], add: ['Cable Fly|3', 'Straight-Arm Cable Pulldown|3']),
        $day['loA'],
        $mod($day['upB'], add: ['Cable Fly|3']),
        $day['loB'],
        $mod($day['upC'], add: ['Pec Deck Fly|3']),
        $day['loC'],
    ), ['chest', 'back']),
    $template('Quad and Hamstring Specialisation 6-Day', $specialisation, 'any', ['gain'], $ul6(
        $day['upA'],
        $mod($day['loA'], front: ['Leg Press|3']),
        $day['upB'],
        $mod($day['loB'], add: ['Lying Leg Curl|3']),
        $day['upC'],
        $day['loC'],
    ), ['quads', 'hamstrings']),
];

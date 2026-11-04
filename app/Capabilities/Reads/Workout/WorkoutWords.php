<?php

namespace App\Capabilities\Reads\Workout;

use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Capabilities\Workout\Data\WorkoutChoice;
use App\Capabilities\Workout\Data\WorkoutHelpSection;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\BestSetChange;
use App\Domain\Progression\SetLoad;
use App\Domain\Progression\WorkoutSetType;

/** What the workout screen says (rp-clone WorkoutHelp, WorkoutTypes), word for word. */
final class WorkoutWords
{
    /** WO-021. */
    public const MYOREP_GUIDANCE = 'Rest 5 to 15 seconds between mini-sets, and log the total reps.';

    public const MYOREP_MATCH_GUIDANCE = 'Do as many mini-sets as you need to match the first set\'s reps.';

    /** WO-024. */
    public const WARM_UP_STEPS = [
        '12 reps at a weight you could lift 30 or more times',
        '8 reps at a weight you could lift about 20 times',
        '4 reps at about your 10-rep max',
        'Then your working sets, at 3 RIR',
    ];

    public const WARM_UP_TITLE = 'Warm up first';

    public const WARM_UP_NOTE = 'Warm-up sets are not logged.';

    /** WO-030. */
    public const REST = 'There is no rest timer. Rest until your breathing is back to normal, you feel mentally ready, no supporting muscle is cramping, and you can hit at least 3 to 5 reps. That is usually 30 seconds to 5 minutes. Paired exercises go back to back: note it on the exercise or reorder them.';

    /** WO-023. */
    public const DUMBBELLS = 'For dumbbells, enter the weight of one dumbbell. For exercises done one side at a time, start with your weaker side, match its reps on the other side, and log one side\'s reps.';

    /** PROG-043. */
    public const DELOAD = 'The deload is meant to feel easy. Push the 0 RIR week as hard as possible before it.';

    /** WO-052. */
    public const SICK = 'Feeling unwell? Train lighter or skip the session. A missed workout costs less than a sick week.';

    public const SPORT = 'Training alongside a sport? Tell the app your sport days in your schedule, and expect to take sets off the muscles the sport hits hard.';

    public const LONG_WORKOUTS = 'Sessions running long? Answer "Pushed my limits" honestly in the feedback, or lower the priority of a muscle. Both cut the sets the app plans.';

    /** EX-008. */
    public const SWAP_ADVICE = 'Swap an exercise only for a good reason, such as pain or equipment you can\'t use. Changing exercises often makes your progress harder to see.';

    /** WO-040. */
    public const FEEDBACK_REQUIRED = 'Your feedback is required';

    /** SAFE-016: shown with "A lot of pain". */
    public const PAIN_NOTE = 'Stop this exercise if the pain is sharp, and see a professional if it persists.';

    /** WO-007, PROG-043: the RIR sheet in a deload week. */
    public const DELOAD_RIR = 'This is a deload week. Keep every set easy; there is no RIR target.';

    public const RIR_TITLE = 'Reps in reserve';

    /** WO-007: "This week's target is 2 RIR: stop each set with 2 reps left in the tank." */
    public static function rir(?int $rir): string
    {
        return $rir === null
            ? self::DELOAD_RIR
            : "This week's target is {$rir} RIR: stop each set with {$rir} ".($rir === 1 ? 'rep' : 'reps').' left in the tank.';
    }

    /** @return list<WorkoutHelpSection> the ? sheet, in reading order */
    public static function help(): array
    {
        return [
            new WorkoutHelpSection('Resting by feel', self::REST, 'rest-times'),
            new WorkoutHelpSection('Warm-ups', implode('. ', self::WARM_UP_STEPS).'. '.self::WARM_UP_NOTE, 'warm-ups'),
            new WorkoutHelpSection('Dumbbells and one side at a time', self::DUMBBELLS, null),
            new WorkoutHelpSection('Deload week', self::DELOAD, 'deloads'),
            new WorkoutHelpSection('Training while sick', self::SICK, 'training-while-sick'),
            new WorkoutHelpSection('Training alongside a sport', self::SPORT, 'training-alongside-sport'),
            new WorkoutHelpSection('Long workouts', self::LONG_WORKOUTS, 'long-workouts'),
        ];
    }

    /** @return list<WorkoutChoice> WO-020, WO-021: the set menu's Set type list */
    public static function setTypeChoices(): array
    {
        return array_map(fn (WorkoutSetType $type) => new WorkoutChoice($type->value, self::setTypeLabel($type), self::guidance($type)), WorkoutSetType::cases());
    }

    /** @return list<WorkoutChoice> WO-022: the exercise menu's Add set choices */
    public static function addSetChoices(): array
    {
        return array_map(fn (WorkoutSetType $type) => new WorkoutChoice(
            $type->value,
            $type === WorkoutSetType::RegularStraight ? 'Regular' : self::setTypeLabel($type),
            self::guidance($type),
        ), WorkoutSetType::addable());
    }

    public static function setTypeLabel(WorkoutSetType $type): string
    {
        return match ($type) {
            WorkoutSetType::RegularStraight => 'Regular · Straight',
            WorkoutSetType::RegularDown => 'Regular · Down',
            WorkoutSetType::RegularAscending => 'Regular · Ascending',
            WorkoutSetType::Myorep => 'Myorep (M)',
            WorkoutSetType::MyorepMatch => 'Myorep match (MM)',
        };
    }

    /** WO-021: the one line shown with a myorep type. */
    public static function guidance(WorkoutSetType $type): ?string
    {
        return match ($type) {
            WorkoutSetType::Myorep => self::MYOREP_GUIDANCE,
            WorkoutSetType::MyorepMatch => self::MYOREP_MATCH_GUIDANCE,
            default => null,
        };
    }

    /** "82.5", "85", "81.25": a weight as a field and a label show it. */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** WO-011. */
    public const RECAP_TITLE = 'Workout done';

    public const NO_SETS_LOGGED = 'No sets logged';

    /** WO-011: "48 min", "1 h", "1 h 12 min". */
    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest} min",
            $rest === 0 => "{$hours} h",
            default => "{$hours} h {$rest} min",
        };
    }

    /** WO-011: "4,172 kg". */
    public static function lifted(float $value, WeightUnit $unit): string
    {
        return number_format($value).' '.$unit->value;
    }

    /** WO-011: "82.5 kg × 10", "12 reps" for a set with no weight, or "No sets logged". */
    public static function bestSet(?SetLoad $set, WeightUnit $unit): string
    {
        return match (true) {
            $set === null => self::NO_SETS_LOGGED,
            $set->weight === null => "{$set->reps} ".($set->reps === 1 ? 'rep' : 'reps'),
            default => self::weight($set->weight, $unit)." × {$set->reps}",
        };
    }

    /** WO-011: "+2.5 kg on last week", "−1 rep on last week" or "Same as last week"; good only when it went up. */
    public static function recapChange(BestSetChange $change, WeightUnit $unit): Status
    {
        $sign = fn (float|int $delta) => $delta > 0 ? '+' : '−';
        $text = match (true) {
            $change->weight != 0 => $sign($change->weight).self::weight(abs($change->weight), $unit).' on last week',
            $change->reps !== 0 => $sign($change->reps).abs($change->reps).' '.(abs($change->reps) === 1 ? 'rep' : 'reps').' on last week',
            default => 'Same as last week',
        };

        return new Status($text, ($change->isUp() ? Tone::Good : Tone::Neutral)->value);
    }

    /** "82.5 kg". */
    public static function weight(float $value, WeightUnit $unit): string
    {
        return self::number($value).' '.$unit->value;
    }

    /** FB-006: what the ? icon of each Feedback question says. */
    public static function feedbackHelp(string $kind): WorkoutHelpSection
    {
        return match ($kind) {
            'joint' => new WorkoutHelpSection('Joint pain', 'Rate how the joints around this exercise felt, not how hard it was. Moderate or a lot of pain keeps sets from being added to this exercise next week, and if sets must come off a muscle they come off the exercise that hurt most.', 'feedback-questions'),
            'soreness' => new WorkoutHelpSection('Soreness', 'How long the muscle took to recover after you last trained it. Never sore or healed a while ago says it can take more work next week; still sore says it needs less. It is not asked the first time you train a muscle here, or after a break of over two weeks.', 'feedback-questions'),
            'pump' => new WorkoutHelpSection('Pump', 'How full and worked the muscle felt today. A low pump pushes next week\'s sets up; an amazing pump nudges them down.', 'feedback-questions'),
            default => new WorkoutHelpSection('Volume', 'Was the number of hard sets right? Not enough adds sets next week, just right keeps them, pushed my limits holds them where they are, and too much takes a set off.', 'feedback-questions'),
        };
    }
}

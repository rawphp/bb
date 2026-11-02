<?php

namespace App\Domain\Workout;

use App\Domain\Model\MuscleGroup;

/**
 * The words of a Feedback sheet (FB-002 to FB-006, SAFE-016), from rp-clone's FeedbackSheetModel and WorkoutHelp: each
 * question, its answers by choice value, and what its ? icon says.
 */
final class FeedbackQuestions
{
    /** FB-002. */
    public const JOINT_PAIN = ['none' => 'None', 'low' => 'Low pain', 'moderate' => 'Moderate pain', 'aLot' => 'A lot of pain'];

    /** FB-003. */
    public const SORENESS = [
        'neverGotSore' => 'Never got sore', 'healedAWhileAgo' => 'Healed a while ago',
        'healedJustOnTime' => 'Healed just on time', 'stillSore' => 'I\'m still sore!',
    ];

    /** FB-004. */
    public const PUMP = ['low' => 'Low pump', 'moderate' => 'Moderate pump', 'amazing' => 'Amazing pump'];

    /** FB-005. */
    public const VOLUME = ['notEnough' => 'Not enough', 'justRight' => 'Just right', 'pushedMyLimits' => 'Pushed my limits', 'tooMuch' => 'Too much'];

    /** SAFE-016: shown on the sheet while an exercise is answered "A lot of pain". */
    public const PAIN_NOTE = 'Stop this exercise if the pain is sharp, and see a professional if it persists.';

    /** FB-006: the Learn article every question's help links to. */
    public const ARTICLE = 'feedback-questions';

    private const HELP = [
        'joint' => ['Joint pain', 'Rate how the joints around this exercise felt, not how hard it was. Moderate or a lot of pain keeps sets from being added to this exercise next week, and if sets must come off a muscle they come off the exercise that hurt most.'],
        'soreness' => ['Soreness', 'How long the muscle took to recover after you last trained it. Never sore or healed a while ago says it can take more work next week; still sore says it needs less. It is not asked the first time you train a muscle here, or after a break of over two weeks.'],
        'pump' => ['Pump', 'How full and worked the muscle felt today. A low pump pushes next week\'s sets up; an amazing pump nudges them down.'],
        'volume' => ['Volume', 'Was the number of hard sets right? Not enough adds sets next week, just right keeps them, pushed my limits holds them where they are, and too much takes a set off.'],
    ];

    public static function joint(string $exerciseName): string
    {
        return "How did your joints feel during {$exerciseName}?";
    }

    public static function soreness(MuscleGroup $muscle): string
    {
        return 'How sore did you get in your '.self::words($muscle).' AFTER training them LAST TIME?';
    }

    public static function pump(MuscleGroup $muscle): string
    {
        return 'How much of a pump did you get today in your '.self::words($muscle).'?';
    }

    public static function volume(MuscleGroup $muscle): string
    {
        return 'How was the volume of work (number of hard sets) for your '.self::words($muscle).'?';
    }

    /**
     * FB-006: what the ? icon of a question says, and how its answers change next week.
     *
     * @param  'joint'|'soreness'|'pump'|'volume'  $kind
     * @return array{title: string, body: string, article: string}
     */
    public static function help(string $kind): array
    {
        [$title, $body] = self::HELP[$kind];

        return ['title' => $title, 'body' => $body, 'article' => self::ARTICLE];
    }

    private static function words(MuscleGroup $muscle): string
    {
        return strtolower($muscle->title());
    }
}

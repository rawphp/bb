<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One question of a Feedback sheet (FB-002 to FB-006), with its ? help and its
 * answers. `exercise_id` is the library exercise a joint question is about;
 * null for the muscle questions. `answer` is a choice value, null when
 * unanswered.
 */
final class WorkoutFeedbackQuestion extends CapabilityData
{
    /**
     * @param  list<WorkoutChoice>  $choices
     */
    public function __construct(
        #[Field(enum: ['joint', 'soreness', 'pump', 'volume'])]
        public readonly string $kind,
        public readonly ?string $exercise_id,
        public readonly string $text,
        public readonly WorkoutHelpSection $help,
        #[Field(items: WorkoutChoice::class)]
        public readonly array $choices,
        public readonly ?string $answer,
    ) {}
}

<?php

namespace App\Domain\Progression;

use App\Domain\Model\MuscleGroup;

/**
 * FB-001: the Feedback sheet of one muscle in a session. It falls due once every set of `$dueAfter`, the muscle's
 * last exercise with sets, is logged or skipped.
 */
final readonly class FeedbackSheet
{
    /**
     * @param  array<int, string>  $exercises  FB-002: the exercises asked about joint pain, by library id, in session order
     */
    public function __construct(
        public MuscleGroup $muscle,
        public string $dueAfter,
        public bool $isDue,
        public array $exercises,
    ) {}
}

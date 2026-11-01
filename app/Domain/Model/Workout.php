<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/** A performed session and the feedback given per muscle. */
final readonly class Workout
{
    /**
     * @param  array<string, FeedbackAnswers>  $feedback  keyed by MuscleGroup value
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $date,
        public Session $session,
        public array $feedback = [],
    ) {}
}

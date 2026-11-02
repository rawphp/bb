<?php

namespace App\Domain\Progression;

use App\Domain\Model\Session;

/** Next week's same session, and what happened to each muscle's sets. */
final readonly class NextOccurrence
{
    /**
     * @param  Session  $session  the same session (id, name, slot ids) with next week's sets
     * @param  array<string, MuscleOutcome>  $outcomes  keyed by MuscleGroup value, per muscle that had feedback and a
     *                                                  priority and is trained in the session
     */
    public function __construct(
        public Session $session,
        public array $outcomes,
    ) {}
}

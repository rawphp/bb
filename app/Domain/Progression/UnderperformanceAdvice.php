<?php

namespace App\Domain\Progression;

use App\Domain\Model\MuscleGroup;

/** PROG-051: what a flag recommends for the muscle's next session. */
final readonly class UnderperformanceAdvice
{
    /** PROG-051: the user may ignore it. */
    public bool $canIgnore;

    /**
     * @param  int  $sets  the sets held, not increased
     * @param  float  $weight  last session's weight, repeated instead of increased
     */
    public function __construct(
        public MuscleGroup $muscle,
        public int $sets,
        public float $weight,
        public string $note,
    ) {
        $this->canIgnore = true;
    }
}

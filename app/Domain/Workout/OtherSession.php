<?php

namespace App\Domain\Workout;

use App\Domain\Model\MuscleGroup;
use DateTimeImmutable;

/** Another session of the user, as soreness (FB-007) and the edit lock (FB-009) read it. */
final readonly class OtherSession
{
    /**
     * @param  ?DateTimeImmutable  $startedAt  null when it has not started
     * @param  bool  $isLaterInMesocycle  it is a later week and day of the same mesocycle
     * @param  list<MuscleGroup>  $workingMuscles  the muscles it logged a working set of
     * @param  list<MuscleGroup>  $muscles  the muscles it holds any set of
     */
    public function __construct(
        public ?DateTimeImmutable $startedAt,
        public bool $isSkipped,
        public bool $isLaterInMesocycle,
        public array $workingMuscles,
        public array $muscles,
    ) {}
}

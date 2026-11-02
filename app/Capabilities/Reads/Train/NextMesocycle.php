<?php

namespace App\Capabilities\Reads\Train;

use App\Models\Mesocycle;
use DateTimeImmutable;

/** MESO-060: the mesocycle that is ending and when the next one starts; MESO-064: the active rest. */
final readonly class NextMesocycle
{
    public function __construct(
        public Mesocycle $mesocycle,
        public DateTimeImmutable $start,
        public bool $recommendsActiveRest,
        public bool $tookActiveRest,
    ) {}
}

<?php

namespace App\Domain\Copy;

use App\Domain\MealFit\MealFood;

/** What one destination meal becomes: the source's entries, which replace what it had, and maybe the source's time. */
final readonly class CopyWrite
{
    /**
     * @param  list<MealFood>  $entries  in the source's order, with the source entries' ids
     * @param  ?string  $time  the destination's new "HH:MM"; null keeps its own
     */
    public function __construct(
        public string $sourceId,
        public string $destinationId,
        public array $entries,
        public ?string $time,
    ) {}
}

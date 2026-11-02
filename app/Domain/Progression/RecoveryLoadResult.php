<?php

namespace App\Domain\Progression;

/** The targets after the recovery flags. */
final readonly class RecoveryLoadResult
{
    /**
     * @param  list<PlannedLoad>  $targets
     * @param  ?string  $note  why the weight is what it is; null when no rule applied
     */
    public function __construct(
        public array $targets,
        public ?string $note,
    ) {}
}

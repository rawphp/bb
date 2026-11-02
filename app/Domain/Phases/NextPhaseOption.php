<?php

namespace App\Domain\Phases;

use App\Domain\Model\PhaseType;

/** A choice on the transition screen: a phase type, or the Gain-first sequence (PH-032). */
final readonly class NextPhaseOption
{
    /** @param  ?PhaseType  $phase  null for Gain first */
    private function __construct(
        public ?PhaseType $phase,
    ) {}

    public static function phase(PhaseType $type): self
    {
        return new self($type);
    }

    public static function gainFirst(): self
    {
        return new self(null);
    }

    public function isGainFirst(): bool
    {
        return $this->phase === null;
    }
}

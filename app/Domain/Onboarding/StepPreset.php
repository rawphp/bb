<?php

namespace App\Domain\Onboarding;

/** A ready-made daily step range (ONB-013). */
final readonly class StepPreset
{
    public function __construct(
        public int $min,
        public int $max,
    ) {}

    /** "8k to 13k". */
    public function title(): string
    {
        $low = $this->min === 0 ? '0' : intdiv($this->min, 1000).'k';

        return "{$low} to ".intdiv($this->max, 1000).'k';
    }
}

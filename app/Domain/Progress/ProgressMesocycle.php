<?php

namespace App\Domain\Progress;

use DateTimeImmutable;
use DateTimeZone;

/** A finished (non-draft) mesocycle: $weeks whole weeks from $start. */
final readonly class ProgressMesocycle
{
    public function __construct(
        public string $id,
        public ?string $phaseId,
        public string $name,
        public DateTimeImmutable $start,
        public int $weeks,
    ) {}

    /** Exclusive: the first day after the last week. */
    public function end(DateTimeZone $zone): DateTimeImmutable
    {
        $days = $this->weeks * 7;

        return Days::startOf(Days::startOf($this->start, $zone)->modify("+{$days} days"), $zone);
    }
}

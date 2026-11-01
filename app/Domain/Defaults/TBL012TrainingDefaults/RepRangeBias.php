<?php

namespace App\Domain\Defaults\TBL012TrainingDefaults;

use App\Domain\Model\ClosedRange;

/** TBL-012 rep-range bias: listed rep ranges, mixed, or mixed with more 5 to 10. */
final readonly class RepRangeBias
{
    /**
     * @param  list<ClosedRange>  $ranges  empty unless the bias lists ranges
     */
    private function __construct(
        public bool $mixed,
        public bool $moreFiveToTen,
        public array $ranges,
    ) {}

    public static function ranges(ClosedRange ...$ranges): self
    {
        return new self(false, false, array_values($ranges));
    }

    public static function mixed(): self
    {
        return new self(true, false, []);
    }

    public static function mixedMoreFiveToTen(): self
    {
        return new self(true, true, []);
    }
}

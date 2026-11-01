<?php

namespace App\Domain\Defaults\TBL012TrainingDefaults;

/** Sets per week as a volume landmark plus an offset: TBL-012's start volume and ceiling. */
final readonly class LandmarkOffset
{
    public function __construct(
        public Landmark $landmark,
        public int $sets = 0,
    ) {}

    public static function mv(): self
    {
        return new self(Landmark::Mv);
    }

    public static function mev(int $sets): self
    {
        return new self(Landmark::Mev, $sets);
    }

    public static function mrv(int $sets): self
    {
        return new self(Landmark::Mrv, $sets);
    }
}

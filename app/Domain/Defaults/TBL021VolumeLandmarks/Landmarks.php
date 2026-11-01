<?php

namespace App\Domain\Defaults\TBL021VolumeLandmarks;

final readonly class Landmarks
{
    public function __construct(
        public int $mv,
        public int $mev,
        public int $mrv,
    ) {}
}

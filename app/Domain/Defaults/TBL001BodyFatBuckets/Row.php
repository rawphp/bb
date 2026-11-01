<?php

namespace App\Domain\Defaults\TBL001BodyFatBuckets;

use App\Domain\Model\BodyFatBucket;

final readonly class Row
{
    public function __construct(
        public BodyFatBucket $bucket,
        public string $label,
        public float $leanMassValuePercent,
    ) {}
}

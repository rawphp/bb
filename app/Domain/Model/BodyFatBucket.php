<?php

namespace App\Domain\Model;

/** The four body-fat photo buckets, leanest first (ONB-007). The percent ranges per sex live in TBL001BodyFatBuckets. */
enum BodyFatBucket: string
{
    case Lowest = 'lowest';
    case Low = 'low';
    case High = 'high';
    case Highest = 'highest';
}

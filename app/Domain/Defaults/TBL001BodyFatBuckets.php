<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL001BodyFatBuckets\Row;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\Sex;

/** TBL-001 Body-fat buckets: the four photo buckets per sex and the body-fat percent used for lean mass. */
final class TBL001BodyFatBuckets
{
    /** @return list<Row> */
    public static function rows(Sex $sex): array
    {
        return match ($sex) {
            Sex::Female => [
                new Row(BodyFatBucket::Lowest, 'under 20%', 17),
                new Row(BodyFatBucket::Low, '20-27%', 23.5),
                new Row(BodyFatBucket::High, '27-35%', 31),
                new Row(BodyFatBucket::Highest, 'over 35%', 38),
            ],
            Sex::Male => [
                new Row(BodyFatBucket::Lowest, 'under 15%', 12),
                new Row(BodyFatBucket::Low, '15-22%', 18.5),
                new Row(BodyFatBucket::High, '22-30%', 26),
                new Row(BodyFatBucket::Highest, 'over 30%', 33),
            ],
        };
    }

    public static function bodyFatPercent(Sex $sex, BodyFatBucket $bucket): float
    {
        // Every bucket is in each sex's table.
        foreach (self::rows($sex) as $row) {
            if ($row->bucket === $bucket) {
                return $row->leanMassValuePercent;
            }
        }

        return 0;
    }
}

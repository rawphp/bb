<?php

use App\Domain\Defaults\TBL001BodyFatBuckets;
use App\Domain\Defaults\TBL001BodyFatBuckets\Row;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\Sex;

it('TBL-001 lists the four buckets for each sex', function (Sex $sex, array $labels, array $values) {
    $rows = TBL001BodyFatBuckets::rows($sex);

    expect(array_map(fn (Row $row) => $row->bucket, $rows))
        ->toBe([BodyFatBucket::Lowest, BodyFatBucket::Low, BodyFatBucket::High, BodyFatBucket::Highest])
        ->and(array_map(fn (Row $row) => $row->label, $rows))->toBe($labels)
        ->and(array_map(fn (Row $row) => $row->leanMassValuePercent, $rows))->toEqual($values);
})->with([
    'female' => [Sex::Female, ['under 20%', '20-27%', '27-35%', 'over 35%'], [17, 23.5, 31, 38]],
    'male' => [Sex::Male, ['under 15%', '15-22%', '22-30%', 'over 30%'], [12, 18.5, 26, 33]],
]);

it('TBL-001 looks up the body-fat percent for a sex and bucket', function () {
    expect(TBL001BodyFatBuckets::bodyFatPercent(Sex::Female, BodyFatBucket::Low))->toBe(23.5)
        ->and(TBL001BodyFatBuckets::bodyFatPercent(Sex::Male, BodyFatBucket::Highest))->toBe(33.0);
});

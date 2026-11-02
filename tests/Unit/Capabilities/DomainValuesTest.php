<?php

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\Tone;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ConsumedTotals;
use App\Domain\Model\DietFilter;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Sex;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeightUnit;
use App\Domain\Safety\RecheckOutcomeKind;
use App\Domain\Safety\ScreeningItem;

it('CAP-002 lists every value of each domain enum a contract names, and nothing else', function (array $values, string $enum) {
    $cases = array_column($enum::cases(), 'value');
    sort($cases);
    sort($values);

    expect($values)->toBe($cases);
})->with([
    'muscle groups' => [DomainValues::MUSCLE_GROUPS, MuscleGroup::class],
    'phase types' => [DomainValues::PHASE_TYPES, PhaseType::class],
    'equipment' => [DomainValues::EQUIPMENT, Equipment::class],
    'load types' => [DomainValues::LOAD_TYPES, ExerciseLoadType::class],
    'weight units' => [DomainValues::WEIGHT_UNITS, WeightUnit::class],
    'priorities' => [DomainValues::PRIORITIES, Priority::class],
    'sexes' => [DomainValues::SEXES, Sex::class],
    'pumps' => [DomainValues::PUMPS, Pump::class],
    'volume feedback' => [DomainValues::VOLUME_FEEDBACK, VolumeFeedback::class],
    'soreness' => [DomainValues::SORENESS, Soreness::class],
    'joint pain' => [DomainValues::JOINT_PAIN, JointPain::class],
    'tones' => [DomainValues::TONES, Tone::class],
    'height units' => [DomainValues::HEIGHT_UNITS, HeightUnit::class],
    'consumed totals' => [DomainValues::CONSUMED_TOTALS, ConsumedTotals::class],
    'body fat buckets' => [DomainValues::BODY_FAT_BUCKETS, BodyFatBucket::class],
    'experience levels' => [DomainValues::EXPERIENCE_LEVELS, ExperienceLevel::class],
    'diet filters' => [DomainValues::DIET_FILTERS, DietFilter::class],
    'screening items' => [DomainValues::SCREENING_ITEMS, ScreeningItem::class],
    'recheck outcome kinds' => [DomainValues::RECHECK_OUTCOME_KINDS, RecheckOutcomeKind::class],
]);

it('CAP-002 has a test row for every list', function () {
    expect(array_keys((new ReflectionClass(DomainValues::class))->getConstants()))->toHaveCount(19);
});

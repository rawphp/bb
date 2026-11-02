<?php

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\Tone;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Sex;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeightUnit;

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
]);

it('CAP-002 has a test row for every list', function () {
    expect(array_keys((new ReflectionClass(DomainValues::class))->getConstants()))->toHaveCount(12);
});

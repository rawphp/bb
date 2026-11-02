<?php

use App\Domain\Progression\WorkoutSetType;
use App\Domain\Progression\WorkoutStatus;

it('WO-021 a set type is Regular in three variants, Myorep or Myorep match, stored as a type and a variant', function () {
    expect(array_map(fn (WorkoutSetType $t) => [$t->type(), $t->variant(), $t->badge()], WorkoutSetType::cases()))->toBe([
        ['regular', 'straight', null],
        ['regular', 'down', null],
        ['regular', 'ascending', null],
        ['myorep', null, 'M'],
        ['myorep_match', null, 'MM'],
    ])
        ->and(WorkoutSetType::stored('regular', null))->toBe(WorkoutSetType::RegularStraight)
        ->and(WorkoutSetType::stored('regular', 'down'))->toBe(WorkoutSetType::RegularDown)
        ->and(WorkoutSetType::stored('myorep_match', 'straight'))->toBe(WorkoutSetType::MyorepMatch);
});

it('WO-022 an exercise adds a regular, myorep or myorep match set', function () {
    expect(WorkoutSetType::addable())->toBe([WorkoutSetType::RegularStraight, WorkoutSetType::Myorep, WorkoutSetType::MyorepMatch]);
});

it('WO-010 a session is not started until it has an actual start, and finished or skipped sessions take no change', function () {
    expect(WorkoutStatus::of(isSkipped: false, hasStarted: false, hasFinished: false))->toBe(WorkoutStatus::NotStarted)
        ->and(WorkoutStatus::of(false, true, false))->toBe(WorkoutStatus::InProgress)
        ->and(WorkoutStatus::of(false, true, true))->toBe(WorkoutStatus::Finished)
        ->and(WorkoutStatus::of(true, true, false))->toBe(WorkoutStatus::Skipped)
        ->and(WorkoutStatus::InProgress->refusal())->toBeNull()
        ->and(WorkoutStatus::NotStarted->refusal())->toBe('Start the workout first.')
        ->and(WorkoutStatus::Finished->isEditable())->toBeFalse()
        ->and(WorkoutStatus::NotStarted->isEditable())->toBeTrue();
});

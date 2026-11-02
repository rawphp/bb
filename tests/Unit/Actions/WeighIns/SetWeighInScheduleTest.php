<?php

use App\Actions\WeighIns\SetWeighInSchedule;
use App\Domain\WeighIns\WeighInSchedule;
use App\Models\User;
use App\Models\WeighInScheduleEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/WeighIns/WeighInScheduleModelTests.swift (save, delete).
uses(RefreshDatabase::class);

/** @return list<array{weekdays: list<int>, time: string}> */
function savedSchedule(User $user): array
{
    return WeighInScheduleEntry::query()->where('user_id', $user->id)->orderBy('id')->get()
        ->map(fn (WeighInScheduleEntry $entry) => ['weekdays' => $entry->weekdays, 'time' => $entry->time])->all();
}

it('WT-001 WT-007 replaces the whole schedule with the entries sent, in order, each weekday list sorted', function () {
    $user = User::factory()->create();
    WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [2, 4], 'time' => '06:00']);
    $theirs = WeighInScheduleEntry::factory()->create();

    (new SetWeighInSchedule)($user, [['weekdays' => [5, 1, 3], 'time' => '07:00'], ['weekdays' => [6], 'time' => '08:30']]);

    expect(savedSchedule($user))->toBe([
        ['weekdays' => [1, 3, 5], 'time' => '07:00'],
        ['weekdays' => [6], 'time' => '08:30'],
    ])->and($theirs->fresh())->not->toBeNull();
});

it('WT-007 deleting an entry is sending the rest', function () {
    $user = User::factory()->create();
    WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [1, 2], 'time' => '07:00']);
    WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [3], 'time' => '09:00']);

    (new SetWeighInSchedule)($user, [['weekdays' => [1, 2], 'time' => '07:00']]);

    expect(savedSchedule($user))->toBe([['weekdays' => [1, 2], 'time' => '07:00']]);
});

it('WT-007 refuses a schedule of fewer than 2 weigh-in days and keeps the old one', function () {
    $user = User::factory()->create();
    WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [1, 2], 'time' => '07:00']);

    expect(fn () => (new SetWeighInSchedule)($user, [['weekdays' => [3], 'time' => '07:00'], ['weekdays' => [3], 'time' => '19:00']]))
        ->toThrow(DomainException::class, WeighInSchedule::TOO_FEW_DAYS)
        ->and(savedSchedule($user))->toBe([['weekdays' => [1, 2], 'time' => '07:00']]);
});

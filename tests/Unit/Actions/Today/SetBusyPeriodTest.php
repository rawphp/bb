<?php

use App\Actions\Today\SetBusyPeriod;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\BusyPeriodSetInput;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRebuild;

// SCH-033: Add busy period saves a busy period on the profile, the list RetargetDays::context() reads, and rebuilds
// the days after today as a settings save does (SET-004). The rebuild is FakeRebuild, so these tests show that it
// runs, not what it writes. Today is Wednesday 14 October 2026.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

const BUSY_MEETING = '01JBX0M1N2P3Q4R5S6T7V8W9B1';

const BUSY_SCHOOL_RUN = '01JBX0M1N2P3Q4R5S6T7V8W9B2';

function busyUser(array $periods = []): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['eating_window' => ['start' => '07:00', 'end' => '21:00'], 'busy_periods' => $periods]);

    return $user;
}

function setBusy(User $user, array $values = []): array
{
    return app(SetBusyPeriod::class)($user, BusyPeriodSetInput::validate(array_replace(
        ['id' => BUSY_SCHOOL_RUN, 'label' => 'School run', 'start' => '15:00', 'end' => '15:45', 'weekdays' => [5, 3]],
        $values,
    )));
}

/** @return list<array<string, mixed>> */
function storedBusy(User $user): array
{
    return Profile::query()->where('user_id', $user->id)->firstOrFail()->busy_periods;
}

it('SCH-033 adds a busy period with its id, label, times and weekdays to the profile, and rebuilds the days after today', function () {
    $user = busyUser([['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3, 4, 5]]]);
    $rebuild = FakeRebuild::bind();

    $dates = setBusy($user);

    expect(storedBusy($user))->toEqual([
        ['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3, 4, 5]],
        ['id' => strtolower(BUSY_SCHOOL_RUN), 'label' => 'School run', 'start' => '15:00', 'end' => '15:45', 'weekdays' => [3, 5]],
    ])
        ->and($rebuild->calls)->toHaveCount(1)
        ->and($dates)->toBe(['2026-10-15']);
});

it('SCH-033 edits the busy period with the same id in place, keeping the others', function () {
    $user = busyUser([
        ['id' => strtolower(BUSY_MEETING), 'label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2]],
        ['id' => strtolower(BUSY_SCHOOL_RUN), 'label' => 'School run', 'start' => '15:00', 'end' => '15:45', 'weekdays' => [3]],
    ]);
    FakeRebuild::bind();

    setBusy($user, ['id' => BUSY_MEETING, 'label' => 'Stand-up', 'start' => '09:30', 'end' => '09:45', 'weekdays' => [1, 2, 3, 2]]);

    expect(storedBusy($user))->toEqual([
        ['id' => strtolower(BUSY_MEETING), 'label' => 'Stand-up', 'start' => '09:30', 'end' => '09:45', 'weekdays' => [1, 2, 3]],
        ['id' => strtolower(BUSY_SCHOOL_RUN), 'label' => 'School run', 'start' => '15:00', 'end' => '15:45', 'weekdays' => [3]],
    ]);
});

it('SCH-033 refuses a busy period with no label, no weekdays or no length, naming the field, and saves nothing', function (array $values, string $field) {
    $user = busyUser();
    $rebuild = FakeRebuild::bind();

    $refusal = null;
    try {
        setBusy($user, $values);
    } catch (FieldRefused $e) {
        $refusal = $e;
    }

    expect($refusal?->field)->toBe($field)
        ->and(storedBusy($user))->toBe([])
        ->and($rebuild->calls)->toBe([]);
})->with([
    'a blank label' => [['label' => '  '], 'label'],
    'no weekdays' => [['weekdays' => []], 'weekdays'],
    'the same start and end' => [['start' => '15:00', 'end' => '15:00'], 'end'],
]);

<?php

use App\Actions\WeighIns\LogSteps;
use App\Enums\MeasurementSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dayOfSteps(User $user, string $date): ?Step
{
    return Step::query()->where('user_id', $user->id)->whereDate('date', $date)->first();
}

it('WT-020 logs a typed count for a day, and a typed count again replaces it', function () {
    $user = User::factory()->create();

    expect((new LogSteps)($user, '2026-10-09', 8412, MeasurementSource::Manual))->toBeTrue()
        ->and((new LogSteps)($user, '2026-10-09', 9001, MeasurementSource::Manual))->toBeTrue()
        ->and(dayOfSteps($user, '2026-10-09'))->count->toBe(9001)->source->toBe(MeasurementSource::Manual)
        ->and(Step::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('WT-020 never lets the bridge overwrite a typed count', function () {
    $user = User::factory()->create();
    Step::factory()->for($user)->create(['date' => '2026-10-09', 'count' => 8412]);

    expect((new LogSteps)($user, '2026-10-09', 12000, MeasurementSource::Shortcut))->toBeFalse()
        ->and(dayOfSteps($user, '2026-10-09'))->count->toBe(8412)->source->toBe(MeasurementSource::Manual);
});

it('WT-020 replaces a bridge count with a newer bridge count, and a typed count replaces a bridge count', function () {
    $user = User::factory()->create();
    Step::factory()->for($user)->shortcut()->create(['date' => '2026-10-08', 'count' => 9000]);

    expect((new LogSteps)($user, '2026-10-08', 10230, MeasurementSource::Shortcut))->toBeTrue()
        ->and(dayOfSteps($user, '2026-10-08'))->count->toBe(10230)->source->toBe(MeasurementSource::Shortcut)
        ->and((new LogSteps)($user, '2026-10-08', 10100, MeasurementSource::Manual))->toBeTrue()
        ->and(dayOfSteps($user, '2026-10-08'))->count->toBe(10100)->source->toBe(MeasurementSource::Manual);
});

it('OFF-030 replaying a queued steps log leaves one count for the day', function () {
    $user = User::factory()->create();

    (new LogSteps)($user, '2026-10-09', 8412, MeasurementSource::Manual);
    (new LogSteps)($user, '2026-10-09', 8412, MeasurementSource::Manual);

    expect(Step::query()->where('user_id', $user->id)->sole()->count)->toBe(8412);
});

it('WT-020 keeps each user\'s count to their own day', function () {
    $user = User::factory()->create();
    $theirs = Step::factory()->create(['date' => '2026-10-09', 'count' => 500]);

    (new LogSteps)($user, '2026-10-09', 8412, MeasurementSource::Manual);

    expect($theirs->fresh()->count)->toBe(500)
        ->and(dayOfSteps($user, '2026-10-09')->count)->toBe(8412);
});

it('WT-020 refuses a day the calendar does not have', function () {
    $user = User::factory()->create();

    expect(fn () => (new LogSteps)($user, '2026-02-30', 8412, MeasurementSource::Manual))
        ->toThrow(DomainException::class, 'Enter a real date.')
        ->and(Step::query()->count())->toBe(0);
});

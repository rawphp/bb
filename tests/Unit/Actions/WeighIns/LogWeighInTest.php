<?php

use App\Actions\WeighIns\LogWeighIn;
use App\Capabilities\WeighIns\Data\WaistInput;
use App\Capabilities\WeighIns\Data\WeightInput;
use App\Enums\MeasurementSource;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/WeighIns/WeighInEntryModelTests.swift (log) and
// WeighInLogTests.swift (Health import).
uses(RefreshDatabase::class);

function logWeighIn(User $user, string $weighedAt, WeightInput $weight, ?WaistInput $waist = null, MeasurementSource $source = MeasurementSource::Manual, ?string $id = null): WeighIn
{
    $id ??= (string) Str::ulid();

    return (new LogWeighIn)($user, WeighIn::query()->find($id), $id, $weighedAt, $weight, $waist, $source);
}

it('WT-002 logs a typed weigh-in in kg at the moment the user weighed in, converting pounds', function () {
    $user = User::factory()->create();

    $weighIn = logWeighIn($user, '2026-10-10T08:35:00+01:00', new WeightInput(180, 'lb'));

    expect($weighIn->fresh())
        ->user_id->toBe($user->id)
        ->weight_kg->toBe(81.65)
        ->waist_cm->toBeNull()
        ->source->toBe(MeasurementSource::Manual)
        // The instant the user gave, kept in the app's time zone (UTC), never the server's clock.
        ->and($weighIn->fresh()->weighed_at->format('Y-m-d H:i'))->toBe('2026-10-10 07:35');
});

it('WT-006 logs a weigh-in on a day the schedule does not hold', function () {
    $user = User::factory()->create();
    $user->weighInScheduleEntries()->create(['weekdays' => [1, 5], 'time' => '07:00']);

    // Wednesday 7 October 2026.
    logWeighIn($user, '2026-10-07T07:10:00+00:00', new WeightInput(82, 'kg'));

    expect(WeighIn::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('WT-021 stores an optional waist in cm, converting inches', function () {
    $user = User::factory()->create();

    $weighIn = logWeighIn($user, '2026-10-10T07:00:00+00:00', new WeightInput(82, 'kg'), new WaistInput(34, 'in'));

    expect($weighIn->fresh()->waist_cm)->toBe(86.4);
});

it('WT-002 refuses a weight above 999.99 kg and stores nothing', function () {
    $user = User::factory()->create();

    expect(fn () => logWeighIn($user, '2026-10-10T07:00:00+00:00', new WeightInput(1000, 'kg')))
        ->toThrow(DomainException::class, 'Enter a weight above 0 and up to 999.99 kg.')
        ->and(WeighIn::query()->count())->toBe(0);
});

it('WT-003 imports a bridge weigh-in with its time and the source shortcut, once', function () {
    $user = User::factory()->create();

    $first = logWeighIn($user, '2026-10-10T06:58:00+10:00', new WeightInput(81.6, 'kg'), source: MeasurementSource::Shortcut);
    // The automation runs again and posts the same latest reading under a new ID.
    $again = logWeighIn($user, '2026-10-10T06:58:00+10:00', new WeightInput(81.6, 'kg'), source: MeasurementSource::Shortcut);

    expect($again->id)->toBe($first->id)
        ->and(WeighIn::query()->where('user_id', $user->id)->sole())
        ->source->toBe(MeasurementSource::Shortcut)
        ->weight_kg->toBe(81.6)
        ->and($first->fresh()->weighed_at->format('Y-m-d H:i'))->toBe('2026-10-09 20:58');
});

it('WT-003 still logs a typed weigh-in at the same time as a bridge one, in either order', function () {
    $user = User::factory()->create();

    logWeighIn($user, '2026-10-10T07:00:00+00:00', new WeightInput(81.6, 'kg'), source: MeasurementSource::Shortcut);
    logWeighIn($user, '2026-10-10T07:00:00+00:00', new WeightInput(81.9, 'kg'));
    logWeighIn($user, '2026-10-11T07:00:00+00:00', new WeightInput(81.9, 'kg'));
    logWeighIn($user, '2026-10-11T07:00:00+00:00', new WeightInput(81.6, 'kg'), source: MeasurementSource::Shortcut);

    expect(WeighIn::query()->where('user_id', $user->id)->count())->toBe(4);
});

it('WT-003 imports each user\'s bridge reading, even at the same moment as another user\'s', function () {
    [$user, $other] = User::factory()->count(2)->create();

    logWeighIn($other, '2026-10-10T06:58:00+00:00', new WeightInput(74, 'kg'), source: MeasurementSource::Shortcut);
    $mine = logWeighIn($user, '2026-10-10T06:58:00+00:00', new WeightInput(81.6, 'kg'), source: MeasurementSource::Shortcut);

    expect($mine->user_id)->toBe($user->id)
        ->and($mine->weight_kg)->toBe(81.6)
        ->and(WeighIn::query()->count())->toBe(2);
});

it('OFF-030 replaying a queued weigh-in under its ID returns it unchanged and adds no second one', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    logWeighIn($user, '2026-10-10T07:00:00+00:00', new WeightInput(82, 'kg'), id: $id);
    $replayed = logWeighIn($user, '2026-10-10T07:00:00+00:00', new WeightInput(90, 'kg'), id: $id);

    expect($replayed->id)->toBe($id)
        ->and(WeighIn::query()->sole()->weight_kg)->toBe(82.0);
});

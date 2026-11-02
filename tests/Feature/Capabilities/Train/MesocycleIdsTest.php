<?php

use App\Enums\MesocycleRoute;
use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;

// The mesocycle ids the five writes and mesocycle_show take, across the seam: only the caller's saved mesocycles
// are written, an id is found whatever its letter case, and a deleted draft's id is not reused. Meso 2 runs 5 weeks
// from Monday 7 September; Tuesday 22 September is week 3.

afterEach(fn () => Carbon::setTestNow());

function idsOver(User $user, string $capability, array $input): mixed
{
    return test()->actingAs($user)->postJson("/capabilities/{$capability}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** Each write and the input that names `$id` as the mesocycle it changes, or copies. */
dataset('mesocycle writes', [
    'mesocycle_length_set' => ['mesocycle_length_set', fn (string $id) => ['mesocycle_id' => $id, 'weeks' => 6]],
    'mesocycle_end' => ['mesocycle_end', fn (string $id) => ['mesocycle_id' => $id]],
    'mesocycle_day_add' => ['mesocycle_day_add', fn (string $id) => ['mesocycle_id' => $id, 'day_id' => (string) Str::ulid()]],
    'mesocycle_active_rest_take' => ['mesocycle_active_rest_take', fn (string $id) => ['mesocycle_id' => $id]],
    'mesocycle_copy' => ['mesocycle_copy', fn (string $id) => ['mesocycle_id' => (string) Str::ulid(), 'source_mesocycle_id' => $id]],
]);

it('MESO-007 MESO-001 answers 404 for the caller\'s own draft and own undated row, and deletes neither', function (string $capability, Closure $input) {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $draft = TrainSeed::mesocycle($user, ['is_draft' => true]);
    $undated = TrainSeed::mesocycle($user, ['start_date' => null, 'weeks' => null]);
    $before = [$draft->fresh()->getAttributes(), $undated->fresh()->getAttributes()];

    idsOver($user, $capability, $input($draft->id))->assertNotFound();
    idsOver($user, $capability, $input($undated->id))->assertNotFound();

    expect([$draft->fresh()?->getAttributes(), $undated->fresh()?->getAttributes()])->toBe($before);
})->with('mesocycle writes');

it('MESO-040 MESO-042 MESO-043 MESO-045 MESO-060 finds a mesocycle whatever the case of its id', function (string $capability, array $input) {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['days' => array_map(
        fn (array $day) => array_replace($day, ['weekday' => null]),
        TrainSeed::mesocycle(User::factory()->create())->days,
    )]);
    $id = strtoupper($mesocycle->id);

    idsOver($user, $capability, array_map(fn ($value) => $value === 'ID' ? $id : $value, $input))->assertOk();
})->with([
    'mesocycle_show' => ['mesocycle_show', ['mesocycle_id' => 'ID']],
    'mesocycle_length_set' => ['mesocycle_length_set', ['mesocycle_id' => 'ID', 'weeks' => 6]],
    'mesocycle_end' => ['mesocycle_end', ['mesocycle_id' => 'ID']],
    'mesocycle_day_add' => ['mesocycle_day_add', ['mesocycle_id' => 'ID', 'day_id' => '01JC0000000000000000000000']],
    'mesocycle_copy' => ['mesocycle_copy', ['mesocycle_id' => '01JD0000000000000000000000', 'source_mesocycle_id' => 'ID']],
]);

it('MESO-007 CAP-019 answers 404 for a copy into a deleted mesocycle\'s id, the caller\'s or another user\'s', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $source = TrainSeed::mesocycle($user);
    $mine = TrainSeed::mesocycle($user, ['id' => (string) Str::ulid(), 'is_draft' => true]);
    $theirs = TrainSeed::mesocycle(User::factory()->create(), ['id' => (string) Str::ulid(), 'is_draft' => true]);
    $mine->delete();
    $theirs->delete();

    idsOver($user, 'mesocycle_copy', ['mesocycle_id' => $mine->id, 'source_mesocycle_id' => $source->id])->assertNotFound();
    idsOver($user, 'mesocycle_copy', ['mesocycle_id' => $theirs->id, 'source_mesocycle_id' => $source->id])->assertNotFound();

    expect(Mesocycle::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('MESO-007 CAP-019 a copy into the caller\'s own draft, its id in other letters, updates that draft', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $source = TrainSeed::mesocycle($user);
    $draft = TrainSeed::mesocycle($user, ['id' => strtolower((string) Str::ulid()), 'is_draft' => true]);

    idsOver($user, 'mesocycle_copy', ['mesocycle_id' => strtoupper($draft->id), 'source_mesocycle_id' => $source->id])->assertOk();

    expect($draft->fresh()?->trashed())->toBeFalse()
        ->and($draft->fresh()->route)->toBe(MesocycleRoute::CopyPrevious);
    idsOver($user, 'mesocycle_show', ['mesocycle_id' => $draft->id])->assertOk();
});

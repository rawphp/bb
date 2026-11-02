<?php

use App\Actions\Today\AdjustDay;
use App\Capabilities\Today\Data\DayAdjustInput;
use App\Models\DayTarget;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;

// The HTTP seam of day_adjust_show and day_adjust. The rules are AdjustmentTest's. Plan weeks start on Monday, so
// Wednesday 23 September shares its week with Saturday 26th; Tuesday 22nd is past.

beforeEach(fn () => Carbon::setTestNow('2026-09-23 06:00:00'));
afterEach(fn () => Carbon::setTestNow());

function adjustOver(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function adjustUser(): User
{
    $user = User::factory()->create();
    Plan::factory()->for($user)->create(['start_date' => '2026-08-31', 'review_weekday' => 1]);
    foreach (['2026-09-22', '2026-09-23', '2026-09-26'] as $date) {
        SheetSeed::day($user, $date);
    }

    return $user;
}

it('DAY-020 refuses day_adjust_show and day_adjust when signed out', function (string $name) {
    adjustOver(null, $name, ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 100, 'other_date' => '2026-09-26'])->assertUnauthorized();
})->with(['day_adjust_show', 'day_adjust']);

it('DAY-020 answers 404 for another user\'s meal', function (string $name) {
    adjustUser();

    adjustOver(User::factory()->create(), $name, ['date' => '2026-09-23', 'mode' => 'meals', 'kcal' => 100, 'from_meal_id' => SheetSeed::LUNCH, 'to_meal_id' => SheetSeed::DINNER])
        ->assertNotFound();
})->with(['day_adjust_show', 'day_adjust']);

it('DAY-020 another user\'s day is not this user\'s: it has no targets to adjust', function () {
    adjustUser();

    adjustOver(User::factory()->create(), 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 100, 'other_date' => '2026-09-26'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This day has no targets to adjust yet.');
});

it('DAY-020 answers 422 naming the bad field', function (string $name, array $input, string $field) {
    adjustOver(adjustUser(), $name, $input)
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with([
    'day_adjust with no other_date between days' => ['day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 100], 'other_date'],
    'day_adjust_show with a date that is not one' => ['day_adjust_show', ['date' => 'Wednesday'], 'date'],
]);

it('DAY-020 DAY-021 NUT-021 day_adjust_show matches its screen fixture', function () {
    $response = adjustOver(adjustUser(), 'day_adjust_show', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-26'])
        ->assertOk();

    assertMatchesScreenFixture('day_adjust_show', $response->json('data'));
    expect($response->json('data.day_options'))->toBe([
        ['date' => '2026-09-22', 'label' => 'Tue, Sep 22', 'enabled' => false],
        ['date' => '2026-09-26', 'label' => 'Sat, Sep 26', 'enabled' => true],
    ])
        ->and($response->json('data.day.kcal_text'))->toBe('2,400 kcal')
        ->and($response->json('data.other_day.kcal_text'))->toBe('2,800 kcal')
        ->and($response->json('data.projected_meals.*.kcal_text'))->toBe(['638 kcal', '724 kcal', '1,039 kcal'])
        ->and($response->json('data.note'))->toBe('Small misses are fine. Within 10% on calories and protein each day is a success.')
        ->and($response->json('data.can_save'))->toBeTrue();
});

it('NUT-024 day_adjust_show says why a move to a finished day is refused', function () {
    adjustOver(adjustUser(), 'day_adjust_show', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-22'])
        ->assertOk()
        ->assertJsonPath('data.refusal', 'Tuesday is finished, so its calories can\'t move.')
        ->assertJsonPath('data.can_save', false);
});

it('NUT-021 day_adjust saves both days and says which reads changed', function () {
    $user = adjustUser();

    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-26'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'today_show{"date":"2026-09-23"}', 'today_show{}', 'day_adjust_show{"date":"2026-09-23"}', 'day_redistribute_show{"date":"2026-09-23"}',
            'today_show{"date":"2026-09-26"}', 'day_adjust_show{"date":"2026-09-26"}', 'day_redistribute_show{"date":"2026-09-26"}',
            ...array_map(fn (int $day) => "week_show{\"date\":\"2026-09-{$day}\"}", range(21, 27)),
            'week_show{}',
        ]);

    $days = DayTarget::query()->where('user_id', $user->id)->orderBy('date')->get();
    expect($days->pluck('kcal')->all())->toBe([2600.0, 2400.0, 2800.0])
        ->and($days->pluck('carbs_g')->all())->toBe([320.0, 270.0, 370.0])
        ->and(array_sum(array_column($days[1]->meals, 'carbs_g')))->toEqualWithDelta(270, 0.001)
        ->and(array_sum(array_column($days[2]->meals, 'carbs_g')))->toEqualWithDelta(370, 0.001)
        ->and(array_column($days[1]->meals, 'protein_g'))->toBe([30, 50, 90]);
});

it('DAY-020 day_adjust between meals moves carbs from one meal to the other and keeps the day', function () {
    $user = adjustUser();

    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'meals', 'kcal' => 100, 'from_meal_id' => SheetSeed::LUNCH, 'to_meal_id' => SheetSeed::DINNER])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Moved 100 kcal')
        ->assertJsonPath('data.invalidates', [
            'today_show{"date":"2026-09-23"}', 'today_show{}', 'day_adjust_show{"date":"2026-09-23"}', 'day_redistribute_show{"date":"2026-09-23"}',
        ]);

    $day = DayTarget::query()->whereDate('date', '2026-09-23')->sole();
    expect([$day->kcal, $day->carbs_g])->toBe([2600.0, 320.0])
        ->and(array_column($day->meals, 'carbs_g'))->toEqual([100, 65, 155])
        ->and(array_column($day->meals, 'kcal'))->toEqual([700, 680, 1220]);
});

it('NUT-024 day_adjust refuses a move to a finished day in plain words and saves nothing', function () {
    $user = adjustUser();

    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-22'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Tuesday is finished, so its calories can\'t move.');
});

it('NUT-021 without a plan the week is the day alone, so a move between days is refused, and a move between meals still works', function () {
    $user = User::factory()->create();
    SheetSeed::day($user, '2026-09-23');
    SheetSeed::day($user, '2026-09-26');

    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-26'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Choose another day of this plan week.');
    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'meals', 'kcal' => 100, 'from_meal_id' => SheetSeed::LUNCH, 'to_meal_id' => SheetSeed::DINNER])
        ->assertOk();
});

it('NUT-021 a move between days works with no weight on record', function () {
    $user = adjustUser();

    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-26'])->assertOk();

    expect(DayTarget::query()->orderBy('date')->pluck('kcal')->all())->toBe([2600.0, 2400.0, 2800.0]);
});

it('NUT-021 both days are saved together: a failed second save leaves the first as it was', function () {
    $user = adjustUser();
    $armed = true;
    // Left registered and disarmed after: Event::forget would also drop the model's own listeners.
    Event::listen('eloquent.saving: '.DayTarget::class, function (DayTarget $day) use (&$armed) {
        if ($armed && $day->date->format('Y-m-d') === '2026-09-26') {
            throw new RuntimeException('second day failed');
        }
    });

    try {
        expect(fn () => app(AdjustDay::class)($user, new DayAdjustInput(date: '2026-09-23', mode: 'days', kcal: 200, other_date: '2026-09-26')))
            ->toThrow(RuntimeException::class, 'second day failed');
    } finally {
        $armed = false;
    }

    expect(DayTarget::query()->orderBy('date')->pluck('kcal')->all())->toBe([2600.0, 2600.0, 2600.0]);
});

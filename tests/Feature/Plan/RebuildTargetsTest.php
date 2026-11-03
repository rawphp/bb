<?php

use App\Actions\Plan\RebuildTargets;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('SET-004 rebuilds the rest of the week from the stored plan and saves each day as its row', function () {
    $user = User::factory()->create();
    Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    Profile::factory()->for($user)->create([
        'eating_window' => ['start' => '07:00', 'end' => '21:00'], 'meal_count' => 4,
        'training_days' => [['weekday' => 4, 'start' => '17:30', 'minutes' => 60]],
        'step_plan' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'min' => 6000, 'max' => 10000], range(1, 7)),
    ]);
    Phase::factory()->for($user)->create(['type' => 'fatLoss', 'start_date' => '2026-09-07', 'end_date' => null, 'target_rate_percent_per_week' => -0.5]);

    // Thursday 15th is stored with six meals: the first four keep their ids, the last two are dropped (NUT-030).
    $ids = array_map(fn () => strtolower((string) Str::uuid7()), range(1, 6));
    $meal = fn (string $id) => ['id' => $id, 'name' => 'M', 'time' => '09:00', 'kcal' => 500, 'protein_g' => 30, 'fat_g' => 15, 'carbs_g' => 60];
    $thursday = DayTarget::factory()->for($user)->create(['date' => '2026-10-15', 'meals' => array_map($meal, $ids)]);
    $today = DayTarget::factory()->for($user)->create(['date' => '2026-10-14', 'meals' => [$meal(strtolower((string) Str::uuid7()))]]);

    $written = app(RebuildTargets::class)($user);

    expect($written)->toBe(['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18']);

    $rows = DayTarget::query()->where('user_id', $user->id)->orderBy('date')->get()->keyBy(fn (DayTarget $row) => $row->date->format('Y-m-d'));
    expect($rows->keys()->all())->toBe(['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'])
        ->and($rows['2026-10-15']->id)->toBe($thursday->id)
        ->and(array_column($rows['2026-10-15']->meals, 'id'))->toBe(array_slice($ids, 0, 4))
        ->and($rows['2026-10-15']->kcal)->not->toBe(2000.0)
        ->and($rows['2026-10-14']->meals)->toHaveCount(1)
        ->and($rows['2026-10-14']->updated_at->equalTo($today->updated_at))->toBeTrue();

    // The index follows: the dropped tail meals' rows are gone, and a new day has one row per meal.
    expect(DayTargetMeal::query()->where('day_target_id', $thursday->id)->orderBy('position')->pluck('id')->all())->toBe(array_slice($ids, 0, 4))
        ->and(DayTargetMeal::query()->whereIn('id', array_slice($ids, 4))->count())->toBe(0)
        ->and(DayTargetMeal::query()->where('day_target_id', $rows['2026-10-16']->id)->orderBy('position')->pluck('id')->all())
        ->toBe(array_column($rows['2026-10-16']->meals, 'id'))
        ->and($rows['2026-10-16']->meals)->toHaveCount(4);
});

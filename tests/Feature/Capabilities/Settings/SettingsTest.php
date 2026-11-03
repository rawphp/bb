<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// The HTTP seam of settings_show, settings_update, profile_update, schedule_defaults_update and diet_filters_set
// (SET-001 to SET-009), and settings_show's screen fixtures. The rules are unit tests: SaveProfileTest,
// UpdateScheduleDefaultsTest, UpdateProfileTest, SetDietFiltersTest and ShowSettingsTest. None of these takes a row's
// id, so there is no other user's id to answer 404 for. Today is Wednesday 14 October 2026.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function settingsPost(?User $user, string $name, array $input = []): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** Sam, from the settings_show fixture: on a plan from Monday 7 September that reviews on Mondays. */
function settingsSam(array $profile = []): User
{
    $user = User::factory()->create(['email' => 'sam@example.com', 'coach_on' => true]);
    Profile::factory()->for($user)->create(array_replace([
        'name' => 'Sam', 'sex' => 'male', 'birth_date' => '1990-03-14', 'height_cm' => 178.0, 'body_fat_bucket' => 'low',
        'eating_window' => ['start' => '07:00', 'end' => '21:00'], 'meal_count' => 4, 'diet_filters' => ['dairy_free'],
        'training_days' => [['weekday' => 1, 'start' => '17:30', 'minutes' => 60], ['weekday' => 5, 'start' => '07:00', 'minutes' => 75]],
        'busy_periods' => [['label' => 'Work meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3, 4, 5]]],
        'step_plan' => array_map(fn (int $day) => ['weekday' => $day, 'min' => 7000, 'max' => 9000], range(1, 7)),
    ], $profile));
    Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);

    return $user;
}

function scheduleForm(array $changes = []): array
{
    return array_replace([
        'eating_window_start' => '07:00', 'eating_window_end' => '21:00', 'meal_count' => 4,
        'training_days' => [['weekday' => 1, 'start' => '17:30', 'minutes' => 60], ['weekday' => 5, 'start' => '07:00', 'minutes' => 75]],
        'busy_periods' => [], 'step_plan' => [], 'review_weekday' => null,
    ], $changes);
}

$writes = [
    'profile_update' => [['profile', 'update'], ['settings_show', 'today_show', 'week_show']],
    'settings_update' => [['settings', 'update'], ['settings_show', 'today_show', 'week_show']],
    'schedule_defaults_update' => [['schedule', 'defaults-update'], ['settings_show', 'today_show', 'week_show']],
    'diet_filters_set' => [['diet-filters', 'set'], ['settings_show', 'food_search']],
];

it('SET-004 SET-005 SET-006 registers each settings write on every host surface with its affects, neither reversible nor destructive', function (string $name, array $cli, array $affects) {
    $definition = app(CapabilityRegistry::class)->get($name);

    expect($definition->surfaces)->toEqualCanonicalizing(HostDefinition::SURFACES)
        ->and($definition->readOnly)->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe($affects)
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and([$definition->cliDomain, $definition->cliVerb])->toBe($cli)
        ->and($definition->description)->not->toBe('');
})->with(array_map(fn (string $name, array $row) => [$name, ...$row], array_keys($writes), $writes));

it('SET-001 registers settings_show as a read on every host surface', function () {
    $definition = app(CapabilityRegistry::class)->get('settings_show');

    expect($definition->surfaces)->toEqualCanonicalizing(HostDefinition::SURFACES)
        ->and($definition->readOnly)->toBeTrue()
        ->and([$definition->cliDomain, $definition->cliVerb])->toBe(['settings', 'show']);
});

it('SCH-039 schedule_defaults_update\'s description names training_weekdays_change', function () {
    expect(app(CapabilityRegistry::class)->get('schedule_defaults_update')->description)->toContain('training_weekdays_change');
});

it('SET-001 refuses every settings capability when signed out', function (string $name) {
    settingsPost(null, $name)->assertUnauthorized();
})->with(['settings_show', 'settings_update', 'profile_update', 'schedule_defaults_update', 'diet_filters_set']);

it('SET-001 settings_update answers 422 naming weight_unit for a unit it does not know', function () {
    settingsPost(settingsSam(), 'settings_update', [
        'weight_unit' => 'stone', 'height_unit' => 'cm', 'appearance' => 'system', 'consumed_totals' => 'planned', 'micro_plates' => false,
    ])->assertStatus(422)->assertJsonPath('error.violations.0.field', 'weight_unit');
});

it('SET-004 profile_update answers 422 naming height for a height with no number', function () {
    settingsPost(settingsSam(), 'profile_update', [
        'name' => 'Sam', 'sex' => 'male', 'birth_date' => '1990-03-14', 'height' => ['unit' => 'cm', 'cm' => null, 'feet' => null, 'inches' => null],
        'body_fat_bucket' => 'low', 'uses_glp1' => false, 'experience' => 'intermediate',
    ])->assertStatus(422)->assertJsonPath('error.violations.0.field', 'height');
});

it('SET-006 diet_filters_set answers 422 naming the filter it does not know', function () {
    settingsPost(settingsSam(), 'diet_filters_set', ['filters' => [['filter' => 'keto']]])
        ->assertStatus(422)->assertJsonPath('error.violations.0.field', 'filters.0.filter');
});

it('SET-005 schedule_defaults_update answers 422 naming review_weekday for a new review day, and saves nothing', function () {
    $user = settingsSam();

    settingsPost($user, 'schedule_defaults_update', scheduleForm(['meal_count' => 3, 'review_weekday' => 3]))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'review_weekday')
        ->assertJsonPath('error.violations.0.message', "Changing the review day isn't supported yet.");

    expect(Profile::query()->where('user_id', $user->id)->value('meal_count'))->toBe(4);
});

it('SCH-039 schedule_defaults_update answers 422 naming training_days for new weekdays while a mesocycle is under way', function () {
    $user = settingsSam();
    $phase = Phase::factory()->for($user)->create(['type' => 'fatLoss', 'start_date' => '2026-09-07', 'end_date' => null]);
    Mesocycle::factory()->for($user)->create([
        'phase_id' => $phase->id, 'weeks' => 4, 'start_date' => '2026-10-12', 'muscle_priorities' => null,
        'days' => [['id' => 'a', 'label' => 'A', 'weekday' => 1, 'slots' => [['id' => 'a1', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 10]]]],
    ]);

    settingsPost($user, 'schedule_defaults_update', scheduleForm(['training_days' => [['weekday' => 2, 'start' => '17:30', 'minutes' => 60]]]))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'training_days')
        ->assertJsonPath('error.violations.0.message', 'A mesocycle is under way. Change training weekdays to move its sessions from next week.');
});

it('SET-005 a schedule save over HTTP stores the defaults and names settings_show among the reads to refetch', function () {
    $user = settingsSam();

    $invalidates = settingsPost($user, 'schedule_defaults_update', scheduleForm(['meal_count' => 5]))->assertOk()->json('data.invalidates');

    expect(Profile::query()->where('user_id', $user->id)->value('meal_count'))->toBe(5)
        ->and($invalidates)->toContain('settings_show{}');
});

it('SET-006 diet_filters_set saves the filters and settings_show reads them back', function () {
    $user = settingsSam();

    settingsPost($user, 'diet_filters_set', ['filters' => [['filter' => 'vegan'], ['filter' => 'paleo']]])
        ->assertOk()->assertJsonPath('data.invalidates', ['settings_show{}']);

    expect(settingsPost($user, 'settings_show')->assertOk()->json('data.diet_filters.summary'))->toBe('2 on');
});

it('SET-001 settings_show matches its fixture for Sam, with the Coach and billing on', function () {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key', 'billing.enabled' => true]);

    $data = settingsPost(settingsSam(), 'settings_show')->assertOk()->json('data');

    assertMatchesScreenFixture('settings_show', $data);
    expect($data['notice'])->toBeNull()
        ->and($data['profile']['height']['text'])->toBe('178 cm')
        ->and($data['diet_filters']['summary'])->toBe('Dairy free')
        ->and($data['coach'])->toBe(['text' => 'On', 'tone' => 'good'])
        ->and($data['schedule']['review_weekday'])->toBe(1);
});

it('SET-004 settings_show matches its warning fixture while a failed rebuild is pending', function () {
    config(['billing.enabled' => true]);

    $data = settingsPost(settingsSam(['targets_rebuild_failed_at' => '2026-10-14 09:00:00', 'training_only' => true]), 'settings_show')
        ->assertOk()->json('data');

    assertMatchesScreenFixture('settings_show.warning', $data);
    expect($data['notice'])->toBe(['text' => 'Your changes are saved, but your targets could not be updated. Tap Save to try again.', 'tone' => 'warning'])
        ->and($data['eligibility'])->toBe(['text' => 'Training only', 'tone' => 'info']);
});

it('SET-001 settings_show matches its empty fixture for a new user in lb and feet, before a plan, with the Coach and billing off', function () {
    config(['capabilities-ai.llm.driver' => 'fake', 'billing.enabled' => false]);
    $user = User::factory()->create(['email' => 'alex@example.com']);
    Profile::factory()->for($user)->create([
        'name' => 'Alex', 'sex' => 'female', 'height_cm' => 167.64, 'height_unit' => 'feetInches', 'weight_unit' => 'lb',
        'body_fat_bucket' => 'high', 'training_days' => [], 'busy_periods' => [], 'step_plan' => [], 'diet_filters' => [],
    ]);

    $data = settingsPost($user, 'settings_show')->assertOk()->json('data');

    assertMatchesScreenFixture('settings_show.empty', $data);
    expect($data['profile']['height'])->toBe(['unit' => 'feetInches', 'text' => '5 ft 6 in', 'cm' => null, 'feet' => 5, 'inches' => 6])
        ->and($data['coach'])->toBeNull()
        ->and($data['subscription'])->toBeNull()
        ->and($data['tokens']['text'])->toBe('None')
        ->and($data['schedule']['review_weekday'])->toBeNull();
});

it('SET-004 a profile change over HTTP rebuilds the targets from tomorrow with the real rebuild, and names the rebuilt days', function () {
    // The plan week runs Monday 12 to Sunday 18 October; today is Wednesday 14 October.
    $user = settingsSam(['height_cm' => 168.0, 'weight_kg' => 80.0, 'training_only' => false]);
    Phase::factory()->for($user)->create(['type' => 'fatLoss', 'start_date' => '2026-09-07', 'end_date' => null, 'target_rate_percent_per_week' => -0.5]);
    foreach (['2026-10-13', '2026-10-14', '2026-10-15'] as $date) {
        DayTarget::factory()->for($user)->create(['date' => $date, 'kcal' => 2000]);
    }

    $invalidates = settingsPost($user, 'profile_update', [
        'name' => 'Sam', 'sex' => 'male', 'birth_date' => '1990-03-14', 'height' => ['unit' => 'cm', 'cm' => 190.0, 'feet' => null, 'inches' => null],
        'body_fat_bucket' => 'low', 'uses_glp1' => false, 'experience' => 'intermediate',
    ])->assertOk()->json('data.invalidates');

    $kcal = DayTarget::query()->where('user_id', $user->id)->orderBy('date')->get()
        ->mapWithKeys(fn (DayTarget $row) => [$row->date->format('Y-m-d') => $row->kcal])->all();
    expect(array_keys($kcal))->toBe(['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'])
        ->and($kcal['2026-10-13'])->toBe(2000.0)
        ->and($kcal['2026-10-14'])->toBe(2000.0)
        ->and($kcal['2026-10-15'])->not->toBe(2000.0)
        ->and(Profile::query()->where('user_id', $user->id)->value('targets_rebuild_failed_at'))->toBeNull()
        ->and(Profile::query()->where('user_id', $user->id)->value('height_cm'))->toEqual(190.0)
        ->and($invalidates)->toContain('settings_show{}', 'today_show{"date":"2026-10-15"}', 'today_show{"date":"2026-10-18"}', 'week_show{"date":"2026-10-12"}', 'week_show{}')
        ->and($invalidates)->not->toContain('today_show{"date":"2026-10-14"}');
});

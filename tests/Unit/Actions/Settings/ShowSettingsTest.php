<?php

use App\Actions\Settings\ShowSettings;
use App\Capabilities\Reads\Data\SettingsShowOutput;
use App\Domain\Tokens\TokenScope;
use App\Models\NotificationPref;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// settings_show (SET-001 to SET-011): what Settings and its edit screens show, ported from rp-clone's SettingsModel,
// HeightDisplay and AppearanceSettings. Today is Wednesday 14 October 2026. The screen fixtures are SettingsShowTest's.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function settingsUser(array $profile = [], array $user = []): User
{
    $owner = User::factory()->create($user);
    Profile::factory()->for($owner)->create($profile);

    return $owner;
}

function showSettings(User $user): SettingsShowOutput
{
    return app(ShowSettings::class)($user, CarbonImmutable::now());
}

it('SET-004 shows no notice while the targets are up to date', function () {
    expect(showSettings(settingsUser())->notice)->toBeNull();
});

it('SET-004 shows the warning while a rebuild that failed is pending, pointing at Save', function () {
    $notice = showSettings(settingsUser(['targets_rebuild_failed_at' => '2026-10-13 09:00:00']))->notice;

    expect([$notice?->text, $notice?->tone])
        ->toBe(['Your changes are saved, but your targets could not be updated. Tap Save to try again.', 'warning']);
});

it('SET-001 height reads in whole centimetres, or feet and whole inches', function (float $cm, string $unit, string $text, ?float $shownCm, ?int $feet, ?float $inches) {
    $height = showSettings(settingsUser(['height_cm' => $cm, 'height_unit' => $unit]))->profile->height;

    expect([$height->unit, $height->text, $height->cm, $height->feet, $height->inches])->toBe([$unit, $text, $shownCm, $feet, $inches]);
})->with([
    'cm' => [178.0, 'cm', '178 cm', 178.0, null, null],
    'cm rounds' => [177.6, 'cm', '178 cm', 177.6, null, null],
    'ft and in' => [167.64, 'feetInches', '5 ft 6 in', null, 5, 6.0],
    '11.8 in reads as the next foot' => [182.4, 'feetInches', '6 ft 0 in', null, 5, 11.8],
]);

it('SET-001 each unit setting has its value and both choices', function () {
    $units = showSettings(settingsUser(['weight_unit' => 'lb', 'height_unit' => 'feetInches']))->units;

    expect($units->weight->value)->toBe('lb')
        ->and(array_map(fn ($c) => [$c->value, $c->label], $units->weight->choices))->toBe([['kg', 'kg'], ['lb', 'lb']])
        ->and($units->height->value)->toBe('feetInches')
        ->and(array_map(fn ($c) => [$c->value, $c->label], $units->height->choices))->toBe([['cm', 'cm'], ['feetInches', 'ft and in']]);
});

it('SET-002 appearance is system, light or dark', function () {
    $appearance = showSettings(settingsUser(['appearance' => 'dark']))->appearance;

    expect($appearance->value)->toBe('dark')
        ->and(array_map(fn ($c) => [$c->value, $c->label], $appearance->choices))->toBe([['system', 'System'], ['light', 'Light'], ['dark', 'Dark']]);
});

it('SET-003 consumed totals include planned meals or checked-in only', function () {
    $totals = showSettings(settingsUser(['consumed_totals' => 'checked_in']))->consumed_totals;

    expect($totals->value)->toBe('checked_in')
        ->and(array_map(fn ($c) => $c->label, $totals->choices))->toBe(['Include planned meals', 'Checked-in meals only']);
});

it('SET-004 the profile shows each answer with its choices, and the body-fat buckets for both sexes', function () {
    $profile = showSettings(settingsUser([
        'name' => 'Sam', 'sex' => 'male', 'birth_date' => '1990-03-14', 'body_fat_bucket' => 'low', 'uses_glp1' => true, 'experience' => 'advanced',
    ]))->profile;

    expect($profile->name)->toBe('Sam')
        ->and($profile->sex->value)->toBe('male')
        ->and(array_map(fn ($c) => $c->label, $profile->sex->choices))->toBe(['Male', 'Female'])
        ->and($profile->birth_date)->toBe('1990-03-14')
        ->and($profile->birth_date_text)->toBe('14 March 1990')
        ->and([$profile->body_fat->value, $profile->body_fat->text])->toBe(['low', '15-22%'])
        ->and(array_map(fn ($c) => $c->label, $profile->body_fat->choices->female))->toBe(['under 20%', '20-27%', '27-35%', 'over 35%'])
        ->and($profile->uses_glp1)->toBeTrue()
        ->and($profile->experience->value)->toBe('advanced')
        ->and(array_map(fn ($c) => $c->label, $profile->experience->choices))->toBe(['Beginner', 'Intermediate', 'Advanced']);
});

it('SET-006 the diet filters row says None, the one filter, or how many are on', function (array $filters, string $summary) {
    $diet = showSettings(settingsUser(['diet_filters' => $filters]))->diet_filters;

    expect($diet->summary)->toBe($summary)
        ->and(count($diet->choices))->toBe(7)
        ->and(array_values(array_map(fn ($c) => $c->value, array_filter($diet->choices, fn ($c) => $c->selected))))->toBe($filters);
})->with([
    'none' => [[], 'None'],
    'one' => [['vegetarian'], 'Ovo-lacto vegetarian'],
    'three' => [['dairy_free', 'gluten_free', 'vegan'], '3 on'],
]);

it('SET-005 the schedule shows the stored defaults, every weekday\'s step range and the form\'s limits', function () {
    $user = settingsUser([
        'eating_window' => ['start' => '07:00', 'end' => '21:00'], 'meal_count' => 4,
        'training_days' => [['weekday' => 1, 'start' => '17:30', 'minutes' => 60], ['weekday' => 5, 'start' => '07:00', 'minutes' => 75]],
        'busy_periods' => [['label' => 'Work meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2]]],
        'step_plan' => [['weekday' => 2, 'min' => 7000, 'max' => 9000]],
    ]);
    Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);

    $schedule = showSettings($user)->schedule;

    expect([$schedule->eating_window_start, $schedule->eating_window_end, $schedule->meal_count])->toBe(['07:00', '21:00', 4])
        ->and([$schedule->meal_count_min, $schedule->meal_count_max])->toBe([2, 6])
        ->and(array_map(fn ($d) => [$d->weekday, $d->label, $d->short_label], $schedule->weekdays))->toBe([
            [1, 'Monday', 'Mon'], [2, 'Tuesday', 'Tue'], [3, 'Wednesday', 'Wed'], [4, 'Thursday', 'Thu'],
            [5, 'Friday', 'Fri'], [6, 'Saturday', 'Sat'], [7, 'Sunday', 'Sun'],
        ])
        ->and(array_map(fn ($d) => [$d->weekday, $d->label, $d->start, $d->minutes], $schedule->training_days))
        ->toBe([[1, 'Monday', '17:30', 60], [5, 'Friday', '07:00', 75]])
        ->and([$schedule->new_training_day_start, $schedule->new_training_day_minutes])->toBe(['17:30', 60])
        ->and([$schedule->session_minutes_min, $schedule->session_minutes_max, $schedule->session_minutes_step])->toBe([15, 240, 5])
        ->and($schedule->busy_periods[0]->label)->toBe('Work meeting')
        ->and(array_map(fn ($d) => $d->on, $schedule->busy_periods[0]->days))->toBe([true, true, false, false, false, false, false])
        ->and([$schedule->new_busy_period->label, $schedule->new_busy_period->start, $schedule->new_busy_period->end])->toBe(['Busy', '09:00', '10:00'])
        ->and(array_map(fn ($d) => $d->on, $schedule->new_busy_period->days))->toBe([true, true, true, true, true, false, false])
        ->and(array_map(fn ($r) => [$r->weekday, $r->min, $r->max], $schedule->step_plan))->toBe([
            [1, null, null], [2, 7000, 9000], [3, null, null], [4, null, null], [5, null, null], [6, null, null], [7, null, null],
        ])
        ->and($schedule->review_weekday)->toBe(1);
});

it('SET-005 the review day is null until there is a plan', function () {
    expect(showSettings(settingsUser())->schedule->review_weekday)->toBeNull();
});

it('NOTIF-007 the notification switches show the defaults, meals off, until the user changes one', function () {
    $user = settingsUser();
    expect(array_map(fn ($n) => [$n->kind, $n->label, $n->on], showSettings($user)->notifications))->toBe([
        ['weigh_in', 'Weigh-ins', true], ['meal', 'Meals', false], ['workout', 'Workouts', true],
        ['weekly_review', 'Weekly review', true], ['next_mesocycle', 'Next mesocycle', true], ['retarget', 'Meal target changes', true],
    ]);

    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'meal', 'on' => true]);

    expect(showSettings($user)->notifications[1]->on)->toBeTrue();
});

it('SAFE-017 the eligibility row says whether nutrition targets are on', function () {
    $both = showSettings(settingsUser())->eligibility;
    $training = showSettings(settingsUser(['training_only' => true]))->eligibility;

    expect([$both->text, $both->tone])->toBe(['Training and nutrition', 'good'])
        ->and([$training->text, $training->tone])->toBe(['Training only', 'info']);
});

it('SET-010 the tokens row counts the tokens still active', function () {
    $user = settingsUser();
    expect(showSettings($user)->tokens->text)->toBe('None');

    foreach ([null, now()->addMonth(), now()->subDay()] as $expires) {
        $token = new PersonalAccessToken(['name' => 't', 'token' => Str::random(64), 'abilities' => ['*'], 'scope' => TokenScope::Everything, 'expires_at' => $expires]);
        $token->id = (string) Str::ulid();
        $token->tokenable()->associate($user)->save();
    }

    expect(showSettings($user)->tokens->text)->toBe('2 active')
        ->and(showSettings($user)->tokens->tone)->toBe('neutral')
        ->and(PersonalAccessToken::ownedBy($user)->count())->toBe(3);
});

it('SET-010 the Health bridge row says whether the bridge is set up', function () {
    expect(showSettings(settingsUser())->health_bridge->text)->toBe('Not set up');
});

it('SET-010 the coach row is null while the agent is off, and says whether the user has it on otherwise', function () {
    config(['capabilities-ai.llm.driver' => 'fake']);
    expect(showSettings(settingsUser())->coach)->toBeNull();

    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    $on = showSettings(settingsUser(user: ['coach_on' => true]))->coach;
    $off = showSettings(settingsUser(user: ['coach_on' => false]))->coach;

    expect([$on?->text, $on?->tone])->toBe(['On', 'good'])
        ->and([$off?->text, $off?->tone])->toBe(['Off', 'neutral']);
});

it('SET-007 the subscription row is null while billing is off, and the subscription\'s status otherwise', function () {
    config(['billing.enabled' => false]);
    expect(showSettings(settingsUser())->subscription)->toBeNull();

    config(['billing.enabled' => true]);
    expect(showSettings(settingsUser())->subscription?->text)->toBe('No subscription');
});

it('SET-008 SET-009 SET-011 the support and account emails, and the developer section in local and testing only', function () {
    config(['app.support_email' => 'help@example.org']);
    $settings = showSettings(settingsUser(user: ['email' => 'sam@example.com']));

    expect($settings->support_email)->toBe('help@example.org')
        ->and($settings->account_email)->toBe('sam@example.com')
        ->and($settings->developer)->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    expect(showSettings(settingsUser())->developer)->toBeFalse();
    app()->detectEnvironment(fn () => 'testing');
});

it('SET-004 a user with no profile yet is told to finish setup', function () {
    expect(fn () => showSettings(User::factory()->create()))->toThrow(DomainException::class);
});

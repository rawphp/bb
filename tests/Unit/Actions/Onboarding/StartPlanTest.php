<?php

use App\Actions\Onboarding\StartPlan;
use App\Capabilities\Reads\Plan\PlanPayload;
use App\Capabilities\Reads\Today\TodayPayload;
use App\Capabilities\Reads\Today\WeekPayload;
use App\Capabilities\Reads\Train\TrainPayload;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Mesocycle;
use App\Models\OnboardingDraft;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\Template;
use App\Models\User;
use App\Models\WeighInScheduleEntry;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Support\CapabilityFailure;
use Tests\Unit\Actions\Onboarding\StartWorld as W;

// ONB-020 "Start my plan" on the database: the rows the draft becomes, read back by the screens that show them. What
// each answer maps to is OnboardingStartTest's. Today is Saturday 10 October 2026; the draft is StartWorld's.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-10 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('ONB-020 saves the plan, its first week and the schedule, and deletes the draft', function () {
    $user = User::factory()->create();
    W::draft($user);

    app(StartPlan::class)($user);

    $profile = Profile::query()->where('user_id', $user->id)->sole();
    $plan = Plan::query()->where('user_id', $user->id)->sole();
    $phase = Phase::query()->where('user_id', $user->id)->sole();
    $mesocycle = Mesocycle::query()->where('user_id', $user->id)->sole();
    $template = Template::query()->with('slots')->sole();
    expect(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($profile->name)->toBe('Sam')
        ->and($profile->eating_window)->toEqual(['start' => '07:00', 'end' => '20:00'])
        ->and($plan->start_date->format('Y-m-d'))->toBe('2026-10-11')
        ->and($plan->review_weekday)->toBe(6)
        ->and($phase->end_date->format('Y-m-d'))->toBe('2027-01-02')
        ->and($mesocycle->phase_id)->toBe($phase->id)
        ->and($mesocycle->isSaved())->toBeTrue()
        ->and(array_column($mesocycle->dayRows()[0]['slots'], 'exercise_id'))
        ->toBe($template->slots->where('day', 1)->pluck('exercise_id')->all())
        ->and(PlanWeek::query()->where('user_id', $user->id)->pluck('start_date')->map->format('Y-m-d')->all())->toBe(['2026-10-11'])
        ->and(DayTarget::query()->where('user_id', $user->id)->orderBy('date')->pluck('date')->map->format('Y-m-d')->all())
        ->toBe(['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'])
        ->and(DayTargetMeal::query()->where('user_id', $user->id)->count())
        ->toBe(DayTarget::query()->where('user_id', $user->id)->get()->sum(fn (DayTarget $day) => count($day->meals)))
        ->and(WeighInScheduleEntry::query()->where('user_id', $user->id)->get(['weekdays', 'time'])->toArray())
        ->toBe([['weekdays' => [1, 3, 5], 'time' => '07:00']]);
});

it('ONB-020 ONB-031 the screens read the new plan back: Plan, Train, Today and the week strip', function () {
    $user = User::factory()->create();
    W::draft($user);

    app(StartPlan::class)($user);

    $plan = PlanSnapshot::load($user);
    $train = TrainPayload::build(TrainSnapshot::load($user));
    $before = TodayPayload::build(TodaySnapshot::load($user));
    // Monday 12 October: a training day.
    $monday = TodayPayload::build(TodaySnapshot::load($user, '2026-10-12'));
    $week = WeekPayload::build(TodaySnapshot::loadWeek($user, '2026-10-12'));

    expect($plan)->not->toBeNull()
        ->and($plan->placed)->toHaveCount(1)
        ->and(PlanPayload::build($plan)->toArray())->toBeArray()
        ->and($train->toArray())->toBeArray()
        ->and(array_column($before->toArray()['banners'], 'text'))->toBe(['Your plan starts Sun, Oct 11'])
        ->and($monday->totals)->not->toBeNull()
        ->and($monday->meals)->toHaveCount(5)
        ->and($monday->workouts)->toHaveCount(1)
        ->and($monday->weigh_ins)->toHaveCount(1)
        ->and($week->toArray())->toBeArray();
});

it('ONB-005 a training-only setup saves no day targets, and Today and Train still read it', function () {
    $user = User::factory()->create();
    W::draft($user, ['screening' => ['diabetes'], 'goal' => 'muscleGain', 'uses_glp1' => null, 'body_fat_bucket' => null,
        'rate_percent_per_week' => null, 'weeks' => null, 'training_plan' => 'later', 'template_id' => null]);

    app(StartPlan::class)($user);

    expect(Profile::query()->where('user_id', $user->id)->sole()->training_only)->toBeTrue()
        ->and(DayTarget::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Mesocycle::query()->where('user_id', $user->id)->sole()->is_draft)->toBeTrue()
        ->and(TodayPayload::build(TodaySnapshot::load($user, '2026-10-12'))->totals)->toBeNull()
        ->and(TrainPayload::build(TrainSnapshot::load($user))->toArray())->toBeArray();
});

it('ONB-020 refuses a draft that cannot continue, and writes nothing', function () {
    $user = User::factory()->create();
    W::draft($user, ['weigh_ins' => [['weekday' => 1, 'minutes' => 7 * 60]]]);

    expect(fn () => app(StartPlan::class)($user))->toThrow(DomainException::class, 'Too few weigh-ins to continue')
        ->and(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Profile::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Plan::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('ONB-020 refuses to start a second plan for a user who has one', function () {
    $user = User::factory()->create();
    W::draft($user);
    app(StartPlan::class)($user);
    W::draft($user);

    expect(fn () => app(StartPlan::class)($user))->toThrow(CapabilityFailure::class, StartPlan::ALREADY_STARTED)
        ->and(Phase::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

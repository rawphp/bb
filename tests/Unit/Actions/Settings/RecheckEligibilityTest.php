<?php

use App\Actions\Settings\RecheckEligibility;
use App\Domain\Model\PhaseType;
use App\Domain\Safety\RecheckOutcomeKind;
use App\Domain\Safety\ScreeningItem;
use App\Models\DayTarget;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use App\Support\Settings\Recheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

// SAFE-017: what "Re-check eligibility" saves, ported from rp-clone's EligibilityRecheckModelTests. Wednesday
// 14 October 2026; the plan reviews on Mondays, so the plan week runs 12 to 18 October. The nutrition steps a passing
// training-only user still has to answer are onboarding's (next: onboarding). The HTTP seam is EligibilityRecheckTest's.
uses(RefreshDatabase::class);

const RECHECK_PHASE = '11111111-1111-4111-8111-111111111111';

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** A user on a plan from Monday 7 September with one phase, and targets yesterday, today, tomorrow and next Monday. */
function recheckUser(array $profile = [], PhaseType $type = PhaseType::FatLoss, float $rate = -0.5, string $planStart = '2026-09-07', ?string $event = null): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(array_replace(['experience' => 'intermediate'], $profile));
    Plan::factory()->for($user)->create(['start_date' => $planStart, 'review_weekday' => 1]);
    $phase = Phase::factory()->for($user)->make([
        'type' => $type, 'start_date' => $planStart, 'end_date' => '2026-12-07', 'target_rate_percent_per_week' => $rate,
        'event_date' => $event,
    ]);
    $phase->id = RECHECK_PHASE;
    $phase->save();
    foreach (['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-19'] as $date) {
        DayTarget::factory()->for($user)->create(['date' => $date]);
    }

    return $user;
}

/** @param  list<ScreeningItem>  $ticked */
function recheck(User $user, array $ticked = []): Recheck
{
    return app(RecheckEligibility::class)($user, $ticked);
}

function targetDates(User $user): array
{
    return DayTarget::query()->where('user_id', $user->id)->orderBy('date')->pluck('date')->map->format('Y-m-d')->all();
}

/** @return list<array{type: string, start: string, end: ?string, rate: float}> */
function phaseRows(User $user): array
{
    return Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get()->map(fn (Phase $phase) => [
        'type' => $phase->type->value, 'start' => $phase->start_date->format('Y-m-d'),
        'end' => $phase->end_date?->format('Y-m-d'), 'rate' => $phase->target_rate_percent_per_week,
    ])->all();
}

/** @return list<?string> each phase's event, by start */
function phaseEvents(User $user): array
{
    return Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get()
        ->map(fn (Phase $phase) => $phase->event_date?->format('Y-m-d'))->all();
}

$trainingOnly = ['screening' => ['diabetes'], 'training_only' => true, 'body_fat_bucket' => null, 'uses_glp1' => null];

it('SAFE-017 keep saves the answers and changes nothing else', function () {
    $user = recheckUser();

    $recheck = recheck($user);

    expect($recheck->kind)->toBe(RecheckOutcomeKind::Keep)
        ->and($recheck->next)->toBe('done')
        ->and($recheck->message)->toBe('Nothing changed. Your answers are saved.')
        ->and($user->profile->fresh()->only(['screening', 'training_only']))->toBe(['screening' => [], 'training_only' => false])
        ->and(phaseRows($user))->toBe([['type' => 'fatLoss', 'start' => '2026-09-07', 'end' => '2026-12-07', 'rate' => -0.5]])
        ->and(targetDates($user))->toBe(['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-19']);
});

it('SAFE-017 a nutrition user who now fails moves to training only at once, with the reason shown', function () {
    $user = recheckUser();

    $recheck = recheck($user, [ScreeningItem::Diabetes]);

    expect($recheck->kind)->toBe(RecheckOutcomeKind::SwitchToTrainingOnly)
        ->and($recheck->next)->toBe('done')
        ->and($user->profile->fresh()->only(['screening', 'training_only']))->toBe(['screening' => ['diabetes'], 'training_only' => true])
        // PLAN-007: the same type, no rate, for the rest of the phase it replaces.
        ->and(phaseRows($user))->toBe([
            ['type' => 'fatLoss', 'start' => '2026-09-07', 'end' => '2026-10-12', 'rate' => -0.5],
            ['type' => 'fatLoss', 'start' => '2026-10-12', 'end' => '2026-12-07', 'rate' => 0.0],
        ])
        ->and($recheck->message)->toBe(
            "Nutrition targets are turned off. You can still use every training feature, log weigh-ins and see your trend weight.\n"
            .'• You have type 1 or type 2 diabetes');
});

it('SAFE-017 only targets after today are removed: today\'s and earlier stay with their logged meals', function () {
    $user = recheckUser();

    $recheck = recheck($user, [ScreeningItem::PregnantOrBreastfeeding]);

    expect(targetDates($user))->toBe(['2026-10-13', '2026-10-14'])
        ->and(DayTarget::onlyTrashed()->where('user_id', $user->id)->count())->toBe(2)
        ->and($recheck->removedDates)->toBe(['2026-10-15', '2026-10-19'])
        // Both plan weeks' strips show those days: 12 to 18 and 19 to 25 October.
        ->and($recheck->weekDates)->toBe([
            '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18',
            '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25',
        ]);
});

it('SAFE-017 PH-043 the phase\'s event goes with the training-only phase that now holds it', function () {
    $user = recheckUser(event: '2026-11-20');

    recheck($user, [ScreeningItem::Diabetes]);

    expect(phaseEvents($user))->toBe([null, '2026-11-20']);
});

it('SAFE-017 PLAN-002 the phases after the switched one keep their dates', function () {
    $user = recheckUser();
    Phase::query()->findOrFail(RECHECK_PHASE)->update(['end_date' => '2026-10-19']);
    Phase::factory()->for($user)->create(['type' => 'muscleGain', 'start_date' => '2026-10-19', 'end_date' => '2027-01-11', 'target_rate_percent_per_week' => 0.25]);

    recheck($user, [ScreeningItem::Diabetes]);

    expect(phaseRows($user))->toBe([
        ['type' => 'fatLoss', 'start' => '2026-09-07', 'end' => '2026-10-12', 'rate' => -0.5],
        ['type' => 'fatLoss', 'start' => '2026-10-12', 'end' => '2026-10-19', 'rate' => 0.0],
        ['type' => 'muscleGain', 'start' => '2026-10-19', 'end' => '2027-01-11', 'rate' => 0.25],
    ]);
});

it('SAFE-017 a switch that fails part way saves nothing: not the answers, the mode, the phases or the removed targets', function () {
    $user = recheckUser();
    // The new phase is the last write, after the profile, the closed phase and the removed targets.
    Phase::creating(fn () => throw new RuntimeException('the phase could not be saved'));
    try {
        expect(fn () => recheck($user, [ScreeningItem::Diabetes]))->toThrow(RuntimeException::class);
    } finally {
        Phase::getEventDispatcher()?->forget('eloquent.creating: '.Phase::class);
    }

    expect($user->profile->fresh()->only(['screening', 'training_only']))->toBe(['screening' => [], 'training_only' => false])
        ->and(phaseRows($user))->toBe([['type' => 'fatLoss', 'start' => '2026-09-07', 'end' => '2026-12-07', 'rate' => -0.5]])
        ->and(targetDates($user))->toBe(['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-19']);
});

it('SAFE-017 a measured item that applies is saved with the ticked ones', function () {
    // 40 kg at 168 cm is a BMI under 17.
    $user = recheckUser(['weight_kg' => 40.0]);

    $recheck = recheck($user, [ScreeningItem::Diabetes]);

    expect($recheck->kind)->toBe(RecheckOutcomeKind::SwitchToTrainingOnly)
        ->and($user->profile->fresh()->screening)->toBe(['bmiUnderLimit', 'diabetes']);
});

it('SAFE-017 a measured item cannot be ticked: age is worked out from the birth date', function () {
    $user = recheckUser();

    $recheck = recheck($user, [ScreeningItem::AgeOutOfRange]);

    expect($recheck->kind)->toBe(RecheckOutcomeKind::Keep)
        ->and($user->profile->fresh()->screening)->toBe([]);
});

it('SAFE-017 ONB-005 a training-only user who now passes goes on to the nutrition steps, with nothing else saved yet', function () use ($trainingOnly) {
    $user = recheckUser($trainingOnly, PhaseType::Maintenance, 0.0);

    $recheck = recheck($user);

    expect($recheck->kind)->toBe(RecheckOutcomeKind::RunNutritionSetup)
        ->and($recheck->next)->toBe('onboarding')
        ->and($recheck->message)->toBe('Your answers allow nutrition targets now. Answer a few nutrition questions to set them up.')
        // Training only until the steps are done: the answers are saved, the mode and the plan are not changed.
        ->and($user->profile->fresh()->only(['screening', 'training_only']))->toBe(['screening' => [], 'training_only' => true])
        ->and(phaseRows($user))->toBe([['type' => 'maintenance', 'start' => '2026-09-07', 'end' => '2026-12-07', 'rate' => 0.0]])
        ->and(targetDates($user))->toHaveCount(4);
});

it('SAFE-017 a training-only user who passes and has not finished the steps is sent to them again', function () use ($trainingOnly) {
    $user = recheckUser($trainingOnly, PhaseType::Maintenance, 0.0);
    recheck($user);

    expect(recheck($user)->kind)->toBe(RecheckOutcomeKind::RunNutritionSetup);
});

it('SAFE-017 a training-only user who still fails keeps training only', function () use ($trainingOnly) {
    $user = recheckUser($trainingOnly, PhaseType::Maintenance, 0.0);

    $recheck = recheck($user, [ScreeningItem::EatingDisorderHistory]);

    expect($recheck->kind)->toBe(RecheckOutcomeKind::Keep)
        ->and($user->profile->fresh()->only(['screening', 'training_only']))->toBe(['screening' => ['eatingDisorderHistory'], 'training_only' => true])
        ->and(phaseRows($user))->toHaveCount(1);
});

it('SAFE-001 SAFE-017 a fat-loss user now trying to conceive picks Maintenance or Muscle gain next', function () {
    $user = recheckUser([], PhaseType::MiniCut, -1.0);

    $recheck = recheck($user, [ScreeningItem::TryingToConceive]);

    expect($recheck->kind)->toBe(RecheckOutcomeKind::LeaveFatLoss)
        ->and($recheck->next)->toBe('onboarding')
        ->and($recheck->message)->toBe("Fat loss isn't available while you're trying to conceive or in fertility treatment. Pick Maintenance or Muscle gain.")
        ->and($user->profile->fresh()->only(['screening', 'training_only']))->toBe(['screening' => ['tryingToConceive'], 'training_only' => false])
        ->and(phaseRows($user))->toBe([['type' => 'miniCut', 'start' => '2026-09-07', 'end' => '2026-12-07', 'rate' => -1.0]]);
});

it('SAFE-001 SAFE-017 trying to conceive on Maintenance keeps the phase', function () {
    $user = recheckUser([], PhaseType::Maintenance, 0.0);

    expect(recheck($user, [ScreeningItem::TryingToConceive])->kind)->toBe(RecheckOutcomeKind::Keep);
});

it('SAFE-017 on a plan that has not started, the first phase turns training only from the plan\'s first day', function () {
    $user = recheckUser([], PhaseType::FatLoss, -0.5, planStart: '2026-10-19');

    recheck($user, [ScreeningItem::Diabetes]);

    expect(phaseRows($user))->toBe([['type' => 'fatLoss', 'start' => '2026-10-19', 'end' => '2026-12-07', 'rate' => 0.0]])
        ->and(targetDates($user))->toBe(['2026-10-13', '2026-10-14']);
});

it('SAFE-017 there is nothing to re-check before the plan is set up', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create();

    recheck($user, [ScreeningItem::Diabetes]);
})->throws(DomainException::class, "Your plan isn't set up yet, so there is nothing to re-check.");

it('SAFE-017 another user\'s plan, phases and targets are not touched', function () {
    $user = recheckUser();
    $other = User::factory()->create();
    Plan::factory()->for($other)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    Phase::factory()->for($other)->create(['start_date' => '2026-09-07', 'end_date' => '2026-12-07']);
    DayTarget::factory()->for($other)->create(['date' => '2026-10-15']);

    recheck($user, [ScreeningItem::Diabetes]);

    expect(targetDates($other))->toBe(['2026-10-15'])
        ->and(phaseRows($other))->toHaveCount(1);
});

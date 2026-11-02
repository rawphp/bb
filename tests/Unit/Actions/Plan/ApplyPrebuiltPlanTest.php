<?php

use App\Actions\Plan\ApplyPrebuiltPlan;
use App\Domain\Plan\PrebuiltPlan;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PlanSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

const FIRST = '01JC1B0000000000000000000A';

function applyPrebuilt(User $user, string $plan, ?Phase $replay = null): array|string
{
    try {
        return app(ApplyPrebuiltPlan::class)($user, PrebuiltPlan::from($plan), FIRST, $replay);
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

/** @return list<array{string, string, string, float}> */
function phasesAfter(User $user, string $from): array
{
    return Phase::query()->where('user_id', $user->id)->where('start_date', '>=', $from)->orderBy('start_date')->get()
        ->map(fn (Phase $p) => [$p->type->value, $p->start_date->format('Y-m-d'), $p->end_date->format('Y-m-d'), $p->target_rate_percent_per_week])->all();
}

it('PLAN-030 the fat-loss plan\'s phases are added where the plan ends, the first under the client\'s id', function () {
    [$user] = PlanSeed::world();

    $added = applyPrebuilt($user, 'fatLoss');

    expect($added)->toHaveCount(3)
        ->and($added[0])->toBe(FIRST)
        ->and(phasesAfter($user, '2027-03-15'))->toBe([
            ['fatLoss', '2027-03-15', '2027-06-21', -0.75],
            ['maintenance', '2027-06-21', '2027-07-05', 0.0],
            ['fatLoss', '2027-07-05', '2027-08-30', -0.75],
        ]);
});

it('PLAN-030 PLAN-007 a training-only user gets the phases with no rates', function () {
    [$user] = PlanSeed::world();
    Profile::query()->where('user_id', $user->id)->update(['training_only' => true]);

    applyPrebuilt($user, 'muscleGain');

    expect(array_unique(array_column(phasesAfter($user, '2027-03-15'), 3)))->toBe([0.0]);
});

it('PLAN-030 PLAN-006 a prebuilt plan cannot follow a phase with no end', function () {
    [$user] = PlanSeed::world();
    Phase::query()->whereKey(W::GAIN)->update(['type' => 'maintenance', 'end_date' => null]);

    expect(applyPrebuilt($user, 'fatLoss'))->toBe('A phase with no end cannot have phases after it.')
        ->and(Phase::query()->where('user_id', $user->id)->count())->toBe(3);
});

it('CAP-019 a replayed apply finds its phases already saved and writes nothing', function () {
    [$user] = PlanSeed::world();
    $first = applyPrebuilt($user, 'fatLoss');

    expect(applyPrebuilt($user, 'fatLoss', Phase::query()->findOrFail(FIRST)))->toBe($first)
        ->and(applyPrebuilt($user, 'fatLoss', Phase::query()->findOrFail(W::CUT)))->toBe('That phase id is already used.')
        ->and(Phase::query()->where('user_id', $user->id)->count())->toBe(6);
});

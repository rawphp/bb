<?php

use App\Actions\Plan\AddPhase;
use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// The action's job is what it saves, so it runs on the database: PlanSeed's plan ends with Muscle gain on 15 March
// 2027, and today is Wednesday 14 October 2026.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

const ADDED = '01JC1A0000000000000000000A';

function addPhase(User $user, string $type, ?int $weeks, ?float $rate = null, ?Phase $replay = null): array|string
{
    try {
        return app(AddPhase::class)($user, ADDED, PhaseType::from($type), $weeks, $rate, $replay);
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

it('PLAN-006 a phase is added where the last one ends, under the client\'s id, at the type\'s default rate', function () {
    [$user] = PlanSeed::world();

    // The phase before it changes too: it can no longer run with no end.
    expect(addPhase($user, 'maintenance', 4))->toBe([W::GAIN, ADDED]);

    $added = Phase::query()->findOrFail(ADDED);
    expect([$added->type->value, $added->start_date->format('Y-m-d'), $added->end_date->format('Y-m-d')])->toBe(['maintenance', '2027-03-15', '2027-04-12'])
        ->and($added->target_rate_percent_per_week)->toBe(0.0);
});

it('PH-004 PH-005 a phase keeps the rate sent, and Maintenance may have no end', function () {
    [$user] = PlanSeed::world();

    addPhase($user, 'maintenance', null, 0.25);

    expect(Phase::query()->findOrFail(ADDED)->end_date)->toBeNull()
        ->and(Phase::query()->findOrFail(ADDED)->target_rate_percent_per_week)->toBe(0.25);
});

it('PLAN-006 an empty plan\'s first phase starts this week', function () {
    $user = TrainSeed::user();

    addPhase($user, 'fatLoss', 8);

    expect(Phase::query()->findOrFail(ADDED)->start_date->format('Y-m-d'))->toBe('2026-10-12');
});

it('PLAN-006 PH-003 a phase is refused after a phase with no end, and outside TBL-010', function () {
    [$user] = PlanSeed::world();

    expect(addPhase($user, 'miniCut', 9))->toBe('A Mini-cut phase can run at most 6 weeks. Diet-break weeks do not count.')
        ->and(addPhase($user, 'maintenance', 53))->toBe('A phase can run at most 52 weeks.')
        ->and(addPhase($user, 'fatLoss', 8, -2.0))->toBe('The rate for Fat loss must be between 0.25% and 1% a week.')
        ->and(addPhase($user, 'muscleGain', null))->toBe('Only Maintenance can run with no end date.');

    Phase::query()->whereKey(W::GAIN)->update(['type' => 'maintenance', 'end_date' => null]);
    expect(addPhase($user, 'maintenance', 4))->toBe('A phase with no end cannot have phases after it.')
        ->and(Phase::query()->find(ADDED))->toBeNull();
});

it('PLAN-007 a training-only phase stores no rate', function () {
    [$user] = PlanSeed::world();
    Profile::query()->where('user_id', $user->id)->update(['training_only' => true]);

    addPhase($user, 'fatLoss', 8, -0.75);

    expect(Phase::query()->findOrFail(ADDED)->target_rate_percent_per_week)->toBe(0.0);
});

it('CAP-019 a replayed add finds its phase already saved and writes nothing', function () {
    [$user] = PlanSeed::world();
    addPhase($user, 'maintenance', 4);

    expect(addPhase($user, 'maintenance', 4, replay: Phase::query()->findOrFail(ADDED)))->toBe([ADDED])
        ->and(addPhase($user, 'fatLoss', 4, replay: Phase::query()->findOrFail(W::CUT)))->toBe('That phase id is already used.')
        ->and(Phase::query()->where('user_id', $user->id)->count())->toBe(4);
});

it('SAFE-001 no Fat loss or Mini-cut can be added while the user is trying to conceive', function () {
    [$user] = PlanSeed::world();
    Profile::query()->where('user_id', $user->id)->update(['screening' => ['tryingToConceive']]);

    expect(addPhase($user, 'miniCut', 4))->toBe("Fat loss isn't available while you're trying to conceive or in fertility treatment. Pick Maintenance or Muscle gain.")
        ->and(addPhase($user, 'maintenance', 4))->toBe([W::GAIN, ADDED]);
});

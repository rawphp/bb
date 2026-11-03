<?php

use App\Actions\Plan\CompletePhaseTransition;
use App\Actions\Plan\EndPhase;
use App\Capabilities\Reads\Plan\PlanPayload;
use App\Capabilities\Support\FieldRefused;
use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// The cut runs 31 August to 19 October and is the plan's last phase; today, 14 October, is in its final week, so its
// transition is due (TransitionPayloadTest has what it offers).
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

const NEXT = '01JC1C0000000000000000000A';

function endingCut(): User
{
    $user = TrainSeed::user();
    Phase::factory()->for($user)->create(['id' => W::CUT, 'type' => 'fatLoss', 'start_date' => '2026-08-31', 'end_date' => '2026-10-19']);

    return $user;
}

/** @param  array<string, mixed>  $input */
function complete(User $user, array $input = [], ?Phase $replay = null): array|string
{
    $input += ['phase_id' => W::CUT, 'choice' => 'maintenance', 'gain_first' => false, 'weeks' => 5, 'template' => 'resensitization', 'calorie_mode' => null, 'step_kcal' => null];
    try {
        return app(CompletePhaseTransition::class)(
            $user, $input['phase_id'], NEXT, PhaseType::from($input['choice']), $input['gain_first'], $input['weeks'],
            $input['template'], $input['calorie_mode'], $input['step_kcal'], $replay,
        );
    } catch (FieldRefused $refused) {
        return "{$refused->field}: {$refused->getMessage()}";
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

/** @return list<array<string, mixed>> */
function savedPhases(User $user): array
{
    return Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get()
        ->map(fn (Phase $p) => array_diff_key($p->getAttributes(), array_flip(['id', 'created_at', 'updated_at'])))->all();
}

it('PH-030 PH-031 Maintenance after the cut is saved from where the cut ends, under the client\'s id', function () {
    $user = endingCut();

    expect(complete($user))->toBe([W::CUT, NEXT]);

    $next = Phase::query()->findOrFail(NEXT);
    expect([$next->type->value, $next->start_date->format('Y-m-d'), $next->end_date->format('Y-m-d'), $next->target_rate_percent_per_week])
        ->toBe(['maintenance', '2026-10-19', '2026-11-23', 0.0])
        ->and(PlanPayload::build(PlanSnapshot::load($user))->toArray()['transition'])->toBeNull();
});

it('PH-036 PH-044 a valid template, calorie mode and step are checked, then dropped: the phases are saved exactly as without them', function () {
    $plain = endingCut();
    complete($plain, ['template' => 'resensitization']);
    $without = savedPhases($plain);
    Phase::query()->delete();
    Phase::query()->withTrashed()->forceDelete();

    $chosen = endingCut();
    expect(complete($chosen, ['template' => 'balanced', 'calorie_mode' => 'step_up', 'step_kcal' => 500]))->toBe([W::CUT, NEXT]);

    expect(array_map(fn (array $row) => array_diff_key($row, ['user_id' => 0]), savedPhases($chosen)))
        ->toBe(array_map(fn (array $row) => array_diff_key($row, ['user_id' => 0]), $without));
});

it('PH-032 Gain first saves its three phases, the first under the client\'s id and the rest under the server\'s', function () {
    $user = endingCut();

    $ids = complete($user, ['choice' => 'muscleGain', 'gain_first' => true, 'weeks' => null, 'template' => 'standard']);

    expect($ids)->toHaveCount(4)
        ->and($ids[1])->toBe(NEXT)
        ->and(array_map(fn (array $p) => [$p['type'], $p['start_date'], $p['end_date'], $p['target_rate_percent_per_week']], array_slice(savedPhases($user), 1)))->toBe([
            ['muscleGain', '2026-10-19', '2026-11-23', 0.375],
            ['maintenance', '2026-11-23', '2026-12-21', 0.0],
            ['muscleGain', '2026-12-21', '2027-02-15', 0.375],
        ]);
});

it('PH-031 PH-036 PH-044 a length, template, calorie mode or step not offered is refused naming the field, and nothing is saved', function () {
    $user = endingCut();

    expect(complete($user, ['weeks' => 20]))->toBe('weeks: Choose one of the lengths offered for Maintenance.')
        ->and(complete($user, ['template' => 'standard']))->toBe('template: That training template is not offered for Maintenance.')
        ->and(complete($user, ['step_kcal' => 300]))->toBe('step_kcal: Step calories up by one of the steps offered: 250 or 500 kcal.')
        ->and(complete($user, ['choice' => 'fatLoss', 'weeks' => 8, 'template' => 'standard', 'calorie_mode' => 'straight']))
        ->toBe('calorie_mode: Calories only step up for Maintenance after Fat loss, so send no calorie mode.')
        ->and(complete($user, ['choice' => 'fatLoss', 'weeks' => 8, 'template' => 'standard', 'step_kcal' => 250]))
        ->toBe('step_kcal: Calories only step up for Maintenance after Fat loss, so send no step.')
        ->and(complete($user, ['choice' => 'muscleGain', 'gain_first' => true, 'weeks' => 8, 'template' => 'standard']))
        ->toBe('weeks: Gain first sets its own lengths, so send no weeks.')
        ->and(complete($user, ['choice' => 'fatLoss', 'gain_first' => true, 'weeks' => null, 'template' => 'standard']))
        ->toBe('choice: That is not one of the next phases offered.')
        ->and(Phase::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('PH-030 a phase with no transition due cannot complete one', function () {
    [$user] = PlanSeed::world();

    expect(complete($user))->toBe('This phase has no transition to complete: it is not ending, or what comes next is already planned.');
});

it('CAP-019 a replayed transition finds its phase already saved and writes nothing', function () {
    $user = endingCut();
    complete($user);

    expect(complete($user, replay: Phase::query()->findOrFail(NEXT)))->toBe([W::CUT, NEXT])
        ->and(complete($user, replay: Phase::query()->findOrFail(W::CUT)))->toBe('That new phase id is already used.')
        ->and(Phase::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('PH-030 PLAN-007 the next phase takes its type\'s rate, and none in training-only mode', function () {
    $user = endingCut();
    complete($user, ['choice' => 'muscleGain', 'weeks' => 8, 'template' => 'standard']);
    $trainingOnly = TrainSeed::user();
    Profile::query()->where('user_id', $trainingOnly->id)->update(['training_only' => true]);
    Phase::factory()->for($trainingOnly)->create(['id' => '01JC1C000000000000000000ZZ', 'type' => 'fatLoss', 'start_date' => '2026-08-31', 'end_date' => '2026-10-19']);
    app(CompletePhaseTransition::class)($trainingOnly, '01JC1C000000000000000000ZZ', '01JC1C000000000000000000ZY', PhaseType::MuscleGain, false, 8, 'standard', null, null);

    expect(Phase::query()->findOrFail(NEXT)->target_rate_percent_per_week)->toBe(0.375)
        ->and(Phase::query()->findOrFail('01JC1C000000000000000000ZY')->target_rate_percent_per_week)->toBe(0.0);
});

it('PH-007 ending the plan\'s last phase with phase_end opens its transition', function () {
    $user = TrainSeed::user();
    Phase::factory()->for($user)->create(['id' => W::CUT, 'type' => 'fatLoss', 'start_date' => '2026-08-31', 'end_date' => '2026-11-23']);
    expect(PlanPayload::build(PlanSnapshot::load($user))->toArray()['transition'])->toBeNull();

    app(EndPhase::class)($user, W::CUT);

    expect(PlanPayload::build(PlanSnapshot::load($user))->toArray()['transition']['next_from_text'])->toBe('What comes next, from 19 Oct');
});

it('SAFE-001 no cut is offered or saved next while the user is trying to conceive', function () {
    $user = TrainSeed::user();
    Profile::query()->where('user_id', $user->id)->update(['screening' => ['tryingToConceive']]);
    Phase::factory()->for($user)->create(['id' => W::CUT, 'type' => 'maintenance', 'start_date' => '2026-08-31', 'end_date' => '2026-10-19', 'target_rate_percent_per_week' => 0]);

    $choices = array_column(PlanPayload::build(PlanSnapshot::load($user))->toArray()['transition']['options'], 'choice');

    expect($choices)->toBe(['muscleGain', 'maintenance'])
        ->and(complete($user, ['choice' => 'fatLoss', 'weeks' => 8, 'template' => 'standard']))->toBe('choice: That is not one of the next phases offered.');
});

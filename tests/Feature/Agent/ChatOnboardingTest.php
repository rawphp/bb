<?php

use App\Models\OnboardingDraft;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Contracts\ProgressStore;
use Rawphp\CapabilitiesAi\Domain\TurnRunner;
use Rawphp\CapabilitiesAi\Support\FakeLlmClient;
use Tests\Unit\Actions\Onboarding\StartWorld;

// Onboarding by chat (AGT-030 to AGT-033) against the fake model: the Coach records the answers, the screening sheet
// sends its own, and the plan starts only on the user's confirm line. A classic boot per test, as AgentTurnTest: the
// turn runner is rebuilt around each scripted model.
uses()->group('warp-isolated');

beforeEach(function () {
    Carbon::setTestNow('2026-10-10 09:00:00');
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
});
afterEach(fn () => Carbon::setTestNow());

/**
 * One chat message, answered by the scripted Coach: the tool calls, then the reply.
 *
 * @param  list<array{0: string, 1: array<string, mixed>}>  $calls  tool name and arguments
 * @return list<array<string, mixed>> the turn's tool results, in order
 */
function coachOnboards(User $user, string $message, array $calls, string $reply = 'Done.'): array
{
    app()->instance(LlmClient::class, new FakeLlmClient([
        ['tool_calls' => array_map(fn (array $call): array => ['name' => $call[0], 'arguments' => $call[1]], $calls)],
        ['content' => $reply],
    ]));
    app()->forgetInstance(TurnRunner::class);

    $turn = test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => $message])->assertCreated()->json('turn_ulid');
    $events = app(ProgressStore::class)->since($turn, 0);

    return array_values(array_map(fn (array $event) => $event['data'], array_filter($events, fn (array $event) => $event['kind'] === 'tool')));
}

/** What the stepper reads (AGT-033). @return array<string, mixed> */
function setupShown(User $user): array
{
    return test()->actingAs($user)->postJson('/capabilities/onboarding_show')->assertOk()->json('data');
}

/** An answer from a screen, as the screening sheet sends it: over HTTP, not by the Coach. */
function sheetAnswers(User $user, array $input): mixed
{
    return test()->actingAs($user)->postJson('/capabilities/onboarding_answer', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** @param  list<array<string, mixed>>  $results */
function allOk(array $results): bool
{
    return collect($results)->every(fn (array $result): bool => $result['ok'] === true);
}

it('AGT-030 AGT-031 AGT-032 AGT-033 runs a full onboarding by chat, with the screening from its sheet and the plan started on the confirm line', function () {
    $user = User::factory()->create();
    $template = StartWorld::template();

    // AGT-031: a few answers at a time, with onboarding_answer, as the user gives them.
    $first = coachOnboards($user, 'I want to lose fat. I\'m Sam, female, born 9 April 1990, 170 cm and 80 kg. I haven\'t dieted lately.', [
        ['onboarding_show', []],
        ['onboarding_answer', ['answers' => ['goal' => 'fatLoss', 'finished_diet_recently' => false], 'move' => 'next']],
        ['onboarding_answer', ['answers' => [
            'name' => 'Sam', 'sex' => 'female', 'birth_date' => '1990-04-09',
            'height' => ['unit' => 'cm', 'cm' => 170], 'weight' => ['unit' => 'kg', 'value' => 80],
        ], 'move' => 'next']],
    ], 'Next is a short health check. Answer it on the sheet that just opened.');

    // AGT-033: the stepper reads the same saved setup, at the step the chat reached.
    $shown = setupShown($user);
    expect(allOk($first))->toBeTrue()
        ->and($shown['step']['key'])->toBe('screening')
        ->and($shown['about_you']['name'])->toBe('Sam')
        ->and($shown['goal']['options'][0]['is_selected'] ?? null)->not->toBeNull();

    // AGT-031: the screening is answered on its sheet, and its Next is the sheet's too.
    sheetAnswers($user, ['answers' => ['screening' => [['item' => 'diabetes', 'is_on' => false]]], 'move' => 'stay'])->assertOk();
    sheetAnswers($user, ['answers' => [], 'move' => 'next'])->assertOk();
    expect(setupShown($user)['step']['key'])->toBe('glp1');

    $second = coachOnboards($user, 'I\'ve answered the health check.', [
        ['onboarding_answer', ['answers' => ['uses_glp1' => false], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['body_fat' => 'low'], 'move' => 'next']],
    ]);
    $rate = collect(setupShown($user)['rate_length']['options'])->firstWhere('is_enabled', true)['value'];

    $third = coachOnboards($user, 'Steady is fine, 12 weeks from tomorrow. I\'m intermediate and train Monday, Wednesday and Friday at 6pm for an hour.', [
        ['onboarding_answer', ['answers' => ['rate_option' => $rate, 'weeks' => 12, 'start_date' => '2026-10-11'], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['experience' => 'intermediate'], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['training_days' => array_map(fn (int $day): array => ['weekday' => $day, 'start_minutes' => 18 * 60, 'length_minutes' => 60], [1, 3, 5])], 'move' => 'next']],
    ]);
    $plan = collect(setupShown($user)['training_plan']['options'])->first(fn (array $option): bool => str_contains($option['value'], (string) $template->id))['value'];

    $fourth = coachOnboards($user, 'The full-body template, please. Weigh-ins on my training days at 7, and your calories.', [
        ['onboarding_answer', ['answers' => ['training_plan' => $plan], 'move' => 'next']],
        ['onboarding_answer', ['answers' => [], 'move' => 'next']],
        ['onboarding_answer', ['answers' => [], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['weigh_ins' => array_map(fn (int $day): array => ['weekday' => $day, 'minutes' => 7 * 60], [1, 3, 5])], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['calories' => 'recommendation'], 'move' => 'next']],
    ], "Here is your plan. Start it from the card, or tell me what to change.\nconfirm: onboarding_complete{}");

    expect(allOk($second))->toBeTrue()
        ->and(allOk($third))->toBeTrue()
        ->and(allOk($fourth))->toBeTrue()
        ->and(setupShown($user)['step']['key'])->toBe('review');

    // AGT-032: words are not the confirmation, so the plan does not start.
    $unconfirmed = coachOnboards($user, 'Looks good, start it.', [['onboarding_complete', []]]);
    expect($unconfirmed[0])->toMatchArray(['name' => 'onboarding_complete', 'ok' => false])
        ->and(Phase::query()->where('user_id', $user->id)->exists())->toBeFalse();

    // The review card's Start my plan sends the confirm line as the whole message.
    $confirmed = coachOnboards($user, 'confirm: onboarding_complete{}', [['onboarding_complete', []]], 'Your plan starts Sunday. Want me to swap any exercises or move a training day?');

    expect($confirmed[0])->toMatchArray(['name' => 'onboarding_complete', 'ok' => true])
        ->and(Phase::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('AGT-031 SAFE-001 refuses the Coach a screening answer, and saves none of that call', function () {
    $user = User::factory()->create();

    $results = coachOnboards($user, 'I have no health conditions.', [
        ['onboarding_answer', ['answers' => ['name' => 'Sam', 'screening' => [['item' => 'diabetes', 'is_on' => true]]], 'move' => 'stay']],
    ]);

    expect($results[0])->toMatchArray(['name' => 'onboarding_answer', 'ok' => false])
        ->and(setupShown($user)['about_you']['name'])->toBe('')
        ->and(collect(setupShown($user)['screening']['rows'])->firstWhere('item', 'diabetes')['is_on'])->toBeFalse();
});

it('AGT-031 SAFE-001 refuses the Coach Next from the health check, so only the sheet moves past it', function () {
    $user = User::factory()->create();
    StartWorld::draft($user, ['step' => 3]);

    $results = coachOnboards($user, 'Skip that, next.', [['onboarding_answer', ['answers' => [], 'move' => 'next']]]);

    expect($results[0])->toMatchArray(['name' => 'onboarding_answer', 'ok' => false])
        ->and(setupShown($user)['step']['key'])->toBe('screening');
});

it('AGT-031 SAFE-001 refuses the Coach an Edit from before the review, so it cannot jump past the health check', function () {
    $user = User::factory()->create();
    StartWorld::draft($user, ['step' => 2]);

    $results = coachOnboards($user, 'Go to the body fat step.', [['onboarding_answer', ['answers' => [], 'move' => 'edit', 'edit_step' => 5]]]);

    expect($results[0])->toMatchArray(['name' => 'onboarding_answer', 'ok' => false])
        ->and(setupShown($user)['step']['key'])->toBe('about_you');
});

it('AGT-032 lets the Coach go back from the review to edit a step', function () {
    $user = User::factory()->create();
    StartWorld::draft($user);

    $results = coachOnboards($user, 'Change my training days.', [['onboarding_answer', ['answers' => [], 'move' => 'edit', 'edit_step' => 8]]]);

    expect(allOk($results))->toBeTrue()
        ->and(setupShown($user)['step']['key'])->toBe('training_days');
});

it('SAFE-001 refuses an MCP client a screening answer too, since it is a chat with no sheet', function () {
    $user = User::factory()->create();

    $result = app(CapabilityBus::class)->invoke('onboarding_answer', ['answers' => ['screening' => [['item' => 'diabetes', 'is_on' => true]]]], ['caller' => 'mcp', 'actor' => $user]);

    expect($result->isOk())->toBeFalse()
        ->and($result->toArray()['error']['message'] ?? '')->toContain('health check');
});

<?php

use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Rawphp\CapabilitiesAi\Contracts\ConversationContextProvider;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Contracts\ProgressStore;
use Rawphp\CapabilitiesAi\Domain\TurnRunner;
use Rawphp\CapabilitiesAi\Support\AnthropicLlmClient;
use Rawphp\CapabilitiesAi\Support\FakeLlmClient;
use Tests\Support\TrainSeed;

// A classic boot per test, as AgentTurnTest: the turn runner is rebuilt around the scripted model.
uses()->group('warp-isolated');

/** A conversation for the user with these messages, then a turn. @return array{string, string} */
function coachTurn(User $user, array $messages, string $conversation = '01JC0NVERSAT10N00000000000', string $turn = '01JTURN0000000000000000001'): array
{
    $store = app(ConversationStore::class);
    $row = $store->createConversation($conversation, null, (string) $user->id);
    foreach ($messages as [$role, $content]) {
        $store->createMessage($row, (string) Str::ulid(), $role, $content);
    }
    $store->createQueuedTurn($row, $turn);

    return [$row->ulid, $turn];
}

function coachMessages(string $conversation, string $turn): array
{
    return app(ConversationContextProvider::class)->messagesForTurn($conversation, $turn);
}

it('AGT-024 AGT-011 puts the instructions first, then the history, then the turn context just before the user message', function () {
    $this->travelTo('2026-11-03 09:14:00');
    [$conversation, $turn] = coachTurn(TrainSeed::user(), [
        ['user', 'Swap bench for dumbbell press.'],
        ['assistant', 'Swapped, from this week on.'],
        ['user', 'What is on today?'],
    ]);

    $messages = coachMessages($conversation, $turn);

    expect($messages)->toHaveCount(5)
        ->and($messages[0]['role'])->toBe('system')
        ->and($messages[0]['content'])->toContain('You are the Coach')
        ->and($messages[1])->toBe(['role' => 'user', 'content' => 'Swap bench for dumbbell press.'])
        ->and($messages[2])->toBe(['role' => 'assistant', 'content' => 'Swapped, from this week on.'])
        ->and($messages[3]['role'])->toBe('user')
        ->and($messages[3]['content'])->toContain('Now: Tuesday 3 November 2026, 09:14')
        ->and($messages[3]['content'])->toContain('today_show{}')
        ->and($messages[3]['content'])->toContain('week_show{"date":"2026-10-27"}')
        ->and($messages[4])->toBe(['role' => 'user', 'content' => 'What is on today?']);
});

it('AGT-024 keeps the instructions the same for every turn and user, and changes only the context', function () {
    $this->travelTo('2026-11-03 09:14:00');
    $sam = TrainSeed::user();
    [$c1, $t1] = coachTurn($sam, [['user', 'Hi.']]);
    $before = coachMessages($c1, $t1);

    Mesocycle::query()->where('user_id', $sam->id)->delete();
    TrainSeed::mesocycle($sam);
    $this->travelTo('2026-11-03 17:45:00');
    [$c2, $t2] = coachTurn(TrainSeed::user(), [['user', 'Hi.']], '01JC0NVERSAT10N00000000002', '01JTURN0000000000000000002');
    $after = coachMessages($c2, $t2);

    expect($after[0])->toBe($before[0])
        ->and($after[1]['content'])->not->toBe($before[1]['content']);
});

it('AGT-024 sends the instructions as the cached block and the context as the last user message', function () {
    $this->travelTo('2026-11-03 09:14:00');
    [$conversation, $turn] = coachTurn(TrainSeed::user(), [['user', 'What is on today?']]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Rest day.']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);

    (new AnthropicLlmClient(apiKey: 'k'))->complete(coachMessages($conversation, $turn));

    Http::assertSent(function ($request): bool {
        $data = $request->data();
        $last = end($data['messages']);

        return $data['system'][0]['cache_control']['type'] === 'ephemeral'
            && str_contains($data['system'][0]['text'], 'You are the Coach')
            && ! str_contains($data['system'][0]['text'], 'today_show{}')
            && ! str_contains($data['system'][0]['text'], 'Now:')
            && $last['role'] === 'user'
            && str_contains($last['content'], 'today_show{}')
            && str_ends_with($last['content'], 'What is on today?');
    });
});

/** A running mesocycle for a user, and the clock inside it. */
function runningMesocycle(User $user): Mesocycle
{
    return TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'fatLoss', '2026-08-31')->id]);
}

/** @return array<string, mixed> the single tool event of the turn that a message started */
function sayToCoach(User $user, string $message, array $script): array
{
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    app()->instance(LlmClient::class, new FakeLlmClient($script));
    app()->forgetInstance(TurnRunner::class);

    $response = test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => $message])->assertCreated();
    $tools = array_values(array_filter(app(ProgressStore::class)->since($response->json('turn_ulid'), 0), fn (array $e): bool => $e['kind'] === 'tool'));

    return $tools[0]['data'];
}

it('AGT-010 refuses mesocycle_end from the agent when the message carries no confirmation', function () {
    $this->travelTo('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = runningMesocycle($user);

    $tool = sayToCoach($user, 'End my mesocycle', [
        ['tool_calls' => [['name' => 'mesocycle_end', 'arguments' => ['mesocycle_id' => $mesocycle->id]]]],
        ['content' => 'Please confirm.'],
    ]);

    expect($tool)->toMatchArray(['name' => 'mesocycle_end', 'ok' => false])
        ->and($mesocycle->fresh()->ended_on)->toBeNull();
});

it('AGT-010 lets the agent end the mesocycle when the message carries the matching confirm line', function () {
    $this->travelTo('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = runningMesocycle($user);

    $tool = sayToCoach($user, "Yes, end it.\nconfirm: mesocycle_end{\"mesocycle_id\":\"{$mesocycle->id}\"}", [
        ['tool_calls' => [['name' => 'mesocycle_end', 'arguments' => ['mesocycle_id' => $mesocycle->id]]]],
        ['content' => 'Ended.'],
    ]);

    expect($tool)->toMatchArray(['name' => 'mesocycle_end', 'ok' => true])
        ->and($mesocycle->fresh()->ended_on->format('Y-m-d'))->toBe('2026-09-23');
});

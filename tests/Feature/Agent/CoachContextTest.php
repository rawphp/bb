<?php

use App\Capabilities\Support\BillingGate;
use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\CatalogPresenter;
use Rawphp\Capabilities\Support\CapabilityResult;
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

/** A conversation for the user, built as the package builds one: each user message is followed by its turn. @return array{string, string} the conversation and the last turn */
function coachTurn(User $user, array $messages, string $conversation = '01JC0NVERSAT10N00000000000', string $turn = '01JTURN0000000000000000001'): array
{
    $store = app(ConversationStore::class);
    $row = $store->createConversation($conversation, null, (string) $user->id);
    $lastUser = array_key_last(array_filter($messages, fn (array $m): bool => $m[0] === 'user'));
    foreach ($messages as $i => [$role, $content]) {
        $store->createMessage($row, (string) Str::ulid(), $role, $content);
        if ($role === 'user') {
            $store->createQueuedTurn($row, $i === $lastUser ? $turn : (string) Str::ulid());
        }
    }

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

it('AGT-011 holds today, the current phase and mesocycle, last week and this week, and no read that is not registered', function () {
    $this->travelTo('2026-11-03 09:14:00');
    [$conversation, $turn] = coachTurn(TrainSeed::user(), [['user', 'Hi.']]);

    $context = coachMessages($conversation, $turn)[1]['content'];

    expect($context)->toContain("today_show{}\n")
        ->and($context)->toContain("train_show{}\n")
        ->and($context)->toContain("week_show{\"date\":\"2026-10-27\"}\n")
        ->and($context)->toContain("week_show{}\n")
        ->and($context)->toContain("plan_show{}\n")
        ->and($context)->not->toContain('settings_show');
});

it('AGT-011 AGT-004 builds the context from the conversation owner\'s data only, every read as that owner', function () {
    $this->travelTo('2026-09-23 10:00:00');
    $alex = TrainSeed::user();
    $blair = TrainSeed::user();
    TrainSeed::mesocycle($alex, ['name' => 'Alex Strength Block', 'phase_id' => TrainSeed::phase($alex, 'fatLoss', '2026-08-31')->id]);
    TrainSeed::mesocycle($blair, ['name' => 'Blair Hypertrophy Block', 'phase_id' => TrainSeed::phase($blair, 'fatLoss', '2026-08-31')->id]);
    [$conversation, $turn] = coachTurn($blair, [['user', 'How is my block going?']], '01JC0NVERSAT10N0000000000B', '01JTURN000000000000000000B');

    $actors = new ArrayObject;
    $bus = app(CapabilityBus::class);
    app()->instance(CapabilityBus::class, new class($bus, $actors) implements CapabilityBus
    {
        public function __construct(private CapabilityBus $bus, private ArrayObject $actors) {}

        public function invoke(string $nameOrAlias, array $input = [], array $options = []): CapabilityResult
        {
            $this->actors[] = [$nameOrAlias, $options['caller'] ?? null, $options['actor']?->getKey()];

            return $this->bus->invoke($nameOrAlias, $input, $options);
        }

        public function catalog(): CatalogPresenter
        {
            return $this->bus->catalog();
        }
    });
    app()->forgetInstance(ConversationContextProvider::class);

    $context = coachMessages($conversation, $turn)[1]['content'];

    expect($context)->toContain('Blair Hypertrophy Block')
        ->and($context)->not->toContain('Alex Strength Block')
        ->and((array) $actors)->toHaveCount(5)
        ->and(array_unique(array_column((array) $actors, 2)))->toBe([$blair->getKey()])
        ->and(array_unique(array_column((array) $actors, 1)))->toBe(['agent']);
});

it('AGT-011 shows the failure of a read the authorizer refuses, as a tool result would', function () {
    $this->travelTo('2026-11-03 09:14:00');
    [$conversation, $turn] = coachTurn(TrainSeed::user(), [['user', 'Hi.']]);
    app()->instance(BillingGate::class, new class implements BillingGate
    {
        public function allows(User $user, CapabilityDefinition $capability): bool
        {
            return $capability->name !== 'today_show';
        }

        public function allowsCoach(User $user): bool
        {
            return true;
        }
    });

    $context = coachMessages($conversation, $turn)[1]['content'];

    expect($context)->toMatch('/today_show\{\}\n\{"ok":false/')
        ->and($context)->toMatch('/train_show\{\}\n\{"ok":true/');
});

it('AGT-024 leaves out the turn context when the conversation has no user', function () {
    $store = app(ConversationStore::class);
    $row = $store->createConversation('01JC0NVERSAT10N00000000009', null, null);
    $store->createMessage($row, '01JMESSAGE0000000000000009', 'user', 'Hi.');
    [$conversation, $turn] = [$row->ulid, $store->createQueuedTurn($row, '01JTURN0000000000000000009')->ulid];

    $messages = coachMessages($conversation, $turn);

    expect($messages)->toHaveCount(2)
        ->and($messages[1])->toBe(['role' => 'user', 'content' => 'Hi.']);
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

it('AGT-010 refuses mesocycle_end when the confirm line is pasted inside a longer message', function () {
    $this->travelTo('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = runningMesocycle($user);

    $tool = sayToCoach($user, "Yes, end it.\nconfirm: mesocycle_end{\"mesocycle_id\":\"{$mesocycle->id}\"}", [
        ['tool_calls' => [['name' => 'mesocycle_end', 'arguments' => ['mesocycle_id' => $mesocycle->id]]]],
        ['content' => 'Please send the line on its own.'],
    ]);

    expect($tool)->toMatchArray(['name' => 'mesocycle_end', 'ok' => false])
        ->and($mesocycle->fresh()->ended_on)->toBeNull();
});

it('AGT-010 lets the agent end the mesocycle when the message is the matching confirm line', function () {
    $this->travelTo('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = runningMesocycle($user);

    $tool = sayToCoach($user, "confirm: mesocycle_end{\"mesocycle_id\":\"{$mesocycle->id}\"}", [
        ['tool_calls' => [['name' => 'mesocycle_end', 'arguments' => ['mesocycle_id' => $mesocycle->id]]]],
        ['content' => 'Ended.'],
    ]);

    expect($tool)->toMatchArray(['name' => 'mesocycle_end', 'ok' => true])
        ->and($mesocycle->fresh()->ended_on->format('Y-m-d'))->toBe('2026-09-23');
});

it('AGT-010 AGT-011 gives a user own message to the turn when another user writes in between', function () {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    app()->instance(LlmClient::class, new FakeLlmClient([['content' => 'Hello A.'], ['content' => 'Hello B.'], ['content' => 'Hello again A.']]));
    app()->forgetInstance(TurnRunner::class);
    [$a, $b] = [User::factory()->create(), User::factory()->create()];

    $first = $this->actingAs($a)->postJson('/capabilities-ai/chat/messages', ['content' => 'First.'])->assertCreated();
    $this->actingAs($b)->postJson('/capabilities-ai/chat/messages', ['content' => 'Other.'])->assertCreated();
    $second = $this->actingAs($a)->postJson('/capabilities-ai/chat/messages', ['content' => 'Second.', 'conversation_ulid' => $first->json('conversation_ulid')])->assertCreated();

    $messages = coachMessages($first->json('conversation_ulid'), $second->json('turn_ulid'));
    $contents = array_column($messages, 'content');

    expect(end($messages))->toBe(['role' => 'user', 'content' => 'Second.'])
        ->and($contents)->toContain('First.', 'Hello A.')
        ->and($contents)->not->toContain('Other.');
});

it('AGT-024 fails for a turn that does not exist', function () {
    coachMessages('01JC0NVERSAT10N00000000000', '01JNOSUCHTURN0000000000000');
})->throws(ModelNotFoundException::class);

it('SET-012 tells the Coach the user\'s local time and last week', function (string $zone, string $now, string $lastWeek) {
    $this->travelTo('2026-11-08 22:30:00');
    $user = TrainSeed::user();
    $user->forceFill(['time_zone' => $zone])->save();
    [$conversation, $turn] = coachTurn($user, [['user', 'Hi.']]);

    $context = coachMessages($conversation, $turn)[1]['content'];

    expect($context)->toContain($now)
        ->and($context)->toContain($lastWeek);
})->with([
    'Australia/Brisbane' => ['Australia/Brisbane', 'Now: Monday 9 November 2026, 08:30 (Australia/Brisbane)', 'week_show{"date":"2026-11-02"}'],
    'UTC' => ['UTC', 'Now: Sunday 8 November 2026, 22:30 (UTC)', 'week_show{"date":"2026-11-01"}'],
]);

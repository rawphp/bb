<?php

use App\Models\User;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Contracts\ProgressStore;
use Rawphp\CapabilitiesAi\Domain\TurnRunner;
use Rawphp\CapabilitiesAi\Models\Turn;
use Rawphp\CapabilitiesAi\Support\FakeLlmClient;

// A classic boot per test: the warm registry would keep asking the base app's authorizer, not the spy.
uses()->group('warp-isolated');

/**
 * Turn the agent on with a scripted model, and record every authorizer decision.
 *
 * @return ArrayObject<int, array{capability: string, caller: string, user: mixed}>
 */
function agentWithScript(array $responses): ArrayObject
{
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    app()->instance(LlmClient::class, new FakeLlmClient($responses));
    app()->forgetInstance(TurnRunner::class);

    $seen = new ArrayObject;
    app()->instance(Authorizer::class, new class($seen) implements Authorizer
    {
        public function __construct(private ArrayObject $seen) {}

        public function authorize(string $capability, mixed $input, mixed $context): bool
        {
            $this->seen[] = [
                'capability' => $capability,
                'caller' => $context instanceof CapabilityContext ? $context->caller() : null,
                'user' => $context instanceof CapabilityContext ? $context->user()?->getAuthIdentifier() : null,
            ];

            return true;
        }
    });

    return $seen;
}

/** @return array<int, array<string, mixed>> the turn's tool events */
function toolEvents(string $turnUlid): array
{
    $events = app(ProgressStore::class)->since($turnUlid, 0);

    return array_values(array_filter($events, fn (array $event) => $event['kind'] === 'tool'));
}

it('AGT-004 runs a tool call from a turn as the conversation user, with caller=agent, through the authorizer', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    User::factory()->create();
    $seen = agentWithScript([
        ['tool_calls' => [['name' => 'account_show', 'arguments' => []]]],
        ['content' => 'You are signed in as sam@example.com.'],
    ]);

    $response = $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Which account is this?'])
        ->assertCreated();

    $tool = toolEvents($response->json('turn_ulid'));

    expect((array) $seen)->toBe([['capability' => 'account_show', 'caller' => 'agent', 'user' => $user->id]])
        ->and($tool)->toHaveCount(1)
        ->and($tool[0]['data'])->toMatchArray(['name' => 'account_show', 'ok' => true]);
});

it('AGT-004 stops every agent tool call with CAPABILITIES_SURFACE_AGENT off', function () {
    $user = User::factory()->create();
    agentWithScript([
        ['tool_calls' => [['name' => 'account_show', 'arguments' => []]]],
        ['content' => 'I can\'t check that right now.'],
    ]);
    $registry = app(CapabilityRegistry::class);
    $registry->withGloballyEnabledSurfaces(['agent' => false] + $registry->globallyEnabledSurfaces());

    $response = $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Which account is this?'])
        ->assertCreated();
    $direct = app(CapabilityBus::class)->invoke('account_show', [], ['caller' => 'agent', 'actor' => $user]);

    // The turn offers no tools, and the bus refuses the agent surface even if a call got through.
    expect(toolEvents($response->json('turn_ulid'))[0]['data'])->toMatchArray(['name' => 'account_show', 'ok' => false, 'error_code' => 'capability_not_in_profile'])
        ->and($direct->errorCode())->toBe('forbidden');
});

it('AGT-005 counts a real turn\'s reported tokens, every round, toward the month', function () {
    config(['agent.tokens_per_month' => 1_000_000]);
    $user = User::factory()->create();
    agentWithScript([
        ['tool_calls' => [['name' => 'account_show', 'arguments' => []]], 'usage' => ['input_tokens' => 1_200, 'output_tokens' => 300]],
        ['content' => 'Done.', 'usage' => ['input_tokens' => 1_500, 'output_tokens' => 200]],
    ]);

    $response = $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'Which account is this?'])
        ->assertCreated();

    expect(Turn::query()->where('ulid', $response->json('turn_ulid'))->value('tokens'))->toBe(3_200);
    $this->actingAs($user)->postJson('/capabilities/agent_status_show')
        ->assertOk()
        ->assertJsonPath('data.tokens_left', 996_800);
});

<?php

use App\Ai\FoodAi;
use App\Capabilities\Nutrition\Data\ScanDescribeInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Support\AnthropicLlmClient;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

/** A stand-in for scan_describe (A_SCAN), which calls the AI the way the real one will. */
function registerDescribeProbe(): void
{
    HostDefinition::write('ai_describe_probe', affects: ['account_show'])
        ->description('Stand-in for scan_describe. Used by AI tests only.')
        ->input(ScanDescribeInput::class)
        ->run(function (ScanDescribeInput $input, CapabilityContext $context): WriteResult {
            /** @var User $user */
            $user = $context->user();
            app(FoodAi::class)->describe($user, $input->description);

            return new WriteResult(invalidates: [ReadKey::for('account_show')]);
        })
        ->register(app(CapabilityRegistry::class));
}

beforeEach(function () {
    Http::preventStrayRequests();
    registerDescribeProbe();
});

it('FOOD-044 a capability that calls the AI with no key denies with a clear message and sends nothing', function () {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => null]);
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/ai_describe_probe', ['description' => 'two eggs'], ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7T8'])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.message', 'AI isn\'t set up on this server.');

    Http::assertNothingSent();
});

it('FOOD-044 a failed AI call answers in plain words without the upstream body or the key', function () {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key', 'capabilities-ai.llm.anthropic.max_retries' => 0]);
    app()->forgetInstance(LlmClient::class);
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'test-key upstream detail']], 400)]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/ai_describe_probe', ['description' => 'two eggs'], ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7T9'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'The AI request failed. Try again.');

    expect($response->getContent())->not->toContain('test-key')->not->toContain('upstream');
});

it('FOOD-044 goes out through the package Anthropic client with the prompt, the description and the answer tool', function () {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    app()->forgetInstance(LlmClient::class);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'answer', 'input' => ['items' => []]]],
    ])]);

    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/ai_describe_probe', ['description' => 'two eggs'], ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7TA'])
        ->assertOk();

    expect(app(LlmClient::class))->toBeInstanceOf(AnthropicLlmClient::class);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages'
        && $r->hasHeader('x-api-key', 'test-key')
        && str_starts_with($r['system'][0]['text'], file_get_contents(resource_path('prompts/describe.md')))
        && $r['messages'] === [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'two eggs']]]]
        && $r['tools'][0]['name'] === 'answer'
        && $r['tools'][0]['input_schema']['required'] === ['items']);
});

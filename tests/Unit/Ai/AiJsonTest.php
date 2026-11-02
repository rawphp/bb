<?php

use App\Ai\AiFailed;
use App\Ai\AiJson;
use App\Ai\AiUnavailable;
use Illuminate\Support\Facades\Http;
use Rawphp\CapabilitiesAi\Contracts\DeadlineAwareLlmClient;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Support\LlmClientDefaults;
use Rawphp\CapabilitiesAi\Support\RetryableLlmException;
use Tests\Support\RecordingLlmClient;

const AI_JSON_SCHEMA = [
    'type' => 'object',
    'properties' => ['ok' => ['type' => 'boolean']],
    'required' => ['ok'],
    'additionalProperties' => false,
];

beforeEach(fn () => Http::preventStrayRequests());

it('FOOD-044 asks the package LlmClient with the prompt as system, the content as the user turn and the schema as the answer tool', function () {
    $llm = RecordingLlmClient::answering(['ok' => true])->install();

    $answer = app(AiJson::class)->ask('describe', [['type' => 'text', 'text' => 'two eggs']], AI_JSON_SCHEMA);

    expect($answer)->toBe(['ok' => true])
        ->and($llm->calls)->toHaveCount(1)
        ->and($llm->system())->toStartWith(file_get_contents(resource_path('prompts/describe.md')))
        ->and($llm->system())->toContain('`answer` tool')
        ->and($llm->userContent())->toBe([['type' => 'text', 'text' => 'two eggs']])
        ->and($llm->calls[0]['tools'])->toHaveCount(1)
        ->and($llm->calls[0]['tools'][0]['name'])->toBe('answer')
        ->and($llm->calls[0]['tools'][0]['input_schema'])->toBe(AI_JSON_SCHEMA);
});

it('FOOD-042 FOOD-043 FOOD-044 RCP-010 says AI is not set up, and asks nothing, when the server has no key', function (array $llm) {
    $client = RecordingLlmClient::answering(['ok' => true])->install();
    config($llm);

    expect(fn () => app(AiJson::class)->ask('describe', [], AI_JSON_SCHEMA))
        ->toThrow(AiUnavailable::class, 'AI isn\'t set up on this server.');
    expect($client->calls)->toBe([]);
})->with([
    'no key' => [['capabilities-ai.llm.anthropic.api_key' => null]],
    'an empty key' => [['capabilities-ai.llm.anthropic.api_key' => '']],
    'the fake driver' => [['capabilities-ai.llm.driver' => 'fake']],
]);

it('fails without the upstream message when the model call throws', function (Throwable $error) {
    RecordingLlmClient::answering([])->install();
    app()->instance(LlmClient::class, new RecordingLlmClient([$error]));

    try {
        app(AiJson::class)->ask('describe', [], AI_JSON_SCHEMA);
        $this->fail('Expected AiFailed.');
    } catch (AiFailed $e) {
        expect($e->getMessage())->toBe('The AI request failed. Try again.')
            ->and($e->getMessage())->not->toContain('test-key');
    }
})->with([
    'a permanent error' => [new RuntimeException('Anthropic API error: 400 (test-key upstream body)')],
    'a transient error' => [new RetryableLlmException('Anthropic API error: 529 (overloaded)', status: 529)],
]);

it('fails when the model answers in text instead of the answer tool', function () {
    (new RecordingLlmClient([['content' => '{"ok": true}']]))->install();

    expect(fn () => app(AiJson::class)->ask('describe', [], AI_JSON_SCHEMA))->toThrow(AiFailed::class);
});

it('fails when the model calls another tool', function () {
    (new RecordingLlmClient([['content' => '', 'tool_calls' => [['id' => 't', 'name' => 'other', 'arguments' => ['ok' => true]]]]]))->install();

    expect(fn () => app(AiJson::class)->ask('describe', [], AI_JSON_SCHEMA))->toThrow(AiFailed::class);
});

it('fails when the answer does not match the schema', function (mixed $arguments) {
    RecordingLlmClient::answering($arguments)->install();

    expect(fn () => app(AiJson::class)->ask('describe', [], AI_JSON_SCHEMA))->toThrow(AiFailed::class);
})->with([
    'a missing field' => [[]],
    'the wrong type' => [['ok' => 'yes']],
    'an extra field' => [['ok' => true, 'note' => 'x']],
    'not an object' => ['ok'],
]);

it('FOOD-043 gives a call that holds the user\'s request open a deadline of 60 seconds', function () {
    RecordingLlmClient::answering([])->install();
    // Like the package's clients, withDeadline() returns a copy and leaves the original as it was.
    $seen = new ArrayObject;
    $client = new class($seen) implements DeadlineAwareLlmClient
    {
        use LlmClientDefaults;

        private ?int $deadline = null;

        public function __construct(private readonly ArrayObject $seen) {}

        public function withDeadline(int $deadlineNs): static
        {
            $copy = clone $this;
            $copy->deadline = $deadlineNs;

            return $copy;
        }

        public function complete(array $messages, array $tools = []): array
        {
            $this->seen[] = $this->deadline;

            return ['tool_calls' => [['id' => 't', 'name' => 'answer', 'arguments' => ['ok' => true]]]];
        }
    };
    app()->instance(LlmClient::class, $client);

    $before = hrtime(true);
    expect(app(AiJson::class)->ask('describe', [], AI_JSON_SCHEMA))->toBe(['ok' => true]);
    $after = hrtime(true);

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toBeGreaterThanOrEqual($before + AiJson::DEADLINE_SECONDS * 1_000_000_000)
        ->and($seen[0])->toBeLessThanOrEqual($after + AiJson::DEADLINE_SECONDS * 1_000_000_000)
        ->and(AiJson::DEADLINE_SECONDS)->toBe(60);
});

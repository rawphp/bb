<?php

namespace Tests\Agent;

use Rawphp\CapabilitiesAi\Contracts\LlmClient;

/**
 * Sits in front of the model a turn talks to, fake or real, and keeps each tool call it made with its
 * arguments and the app's result, and its last reply. The turn's progress events cannot serve: they
 * redact arguments such as an undo token.
 */
final class Recorder implements LlmClient
{
    /** @var list<array{id: string, name: string, arguments: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> tool call id => the app's result */
    private array $results = [];

    private string $reply = '';

    public function __construct(private readonly LlmClient $model) {}

    public function supportsToolRounds(): bool
    {
        return $this->model->supportsToolRounds();
    }

    public function complete(array $messages, array $tools = []): array
    {
        foreach ($messages as $message) {
            if ($message['role'] === 'tool' && isset($message['tool_call_id'])) {
                $this->results[$message['tool_call_id']] = (array) json_decode((string) $message['content'], true);
            }
        }

        $response = $this->model->complete($messages, $tools);
        foreach ($response['tool_calls'] ?? [] as $call) {
            $arguments = $call['arguments'] ?? $call['input'] ?? [];
            $this->calls[] = ['id' => (string) ($call['id'] ?? ''), 'name' => (string) ($call['name'] ?? ''), 'arguments' => is_array($arguments) ? $arguments : []];
        }
        if (($response['tool_calls'] ?? []) === []) {
            $this->reply = (string) ($response['content'] ?? '');
        }

        return $response;
    }

    /** @return list<array{name: string, arguments: array<string, mixed>, ok: bool, result: array<string, mixed>}> */
    public function calls(): array
    {
        return array_map(fn (array $call): array => [
            'name' => $call['name'],
            'arguments' => $call['arguments'],
            'ok' => ($this->results[$call['id']]['ok'] ?? false) === true,
            'result' => $this->results[$call['id']] ?? [],
        ], $this->calls);
    }

    public function reply(): string
    {
        return $this->reply;
    }
}

<?php

namespace Tests\Support;

use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Support\LlmClientDefaults;
use Throwable;

/** A scripted model that keeps every request it was sent, for the AI food tests and Coach turns that call tools. */
final class RecordingLlmClient implements LlmClient
{
    use LlmClientDefaults;

    /** @var list<array{messages: list<array<string, mixed>>, tools: list<array<string, mixed>>}> */
    public array $calls = [];

    /** @param  list<array<string, mixed>|Throwable>  $replies  one per call; the last repeats */
    public function __construct(private readonly array $replies) {}

    /** A model that answers through the answer tool with these arguments. */
    public static function answering(mixed $arguments): self
    {
        return new self([['content' => '', 'tool_calls' => [['id' => 'toolu_1', 'name' => 'answer', 'arguments' => $arguments]]]]);
    }

    /** Turn the AI on with a key, and put this client behind the package's LlmClient. */
    public function install(): self
    {
        config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
        app()->instance(LlmClient::class, $this);

        return $this;
    }

    /** It answers each round from its script, so a turn may send it tool results. */
    public function supportsToolRounds(): bool
    {
        return true;
    }

    public function complete(array $messages, array $tools = []): array
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];
        $reply = $this->replies[min(count($this->calls), count($this->replies)) - 1];
        if ($reply instanceof Throwable) {
            throw $reply;
        }

        return $reply;
    }

    /** The system prompt of the last call. */
    public function system(): string
    {
        $last = end($this->calls) ?: ['messages' => []];

        return implode("\n\n", array_map(
            fn (array $m): string => (string) $m['content'],
            array_values(array_filter($last['messages'], fn (array $m): bool => $m['role'] === 'system')),
        ));
    }

    /** @return list<array<string, mixed>> the user content blocks of the last call */
    public function userContent(): array
    {
        $last = end($this->calls) ?: ['messages' => []];
        foreach ($last['messages'] as $m) {
            if ($m['role'] === 'user') {
                return $m['content'];
            }
        }

        return [];
    }
}

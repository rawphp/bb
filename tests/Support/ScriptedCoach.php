<?php

namespace Tests\Support;

use Rawphp\CapabilitiesAi\Contracts\LlmClient;

/**
 * The Coach in the e2e server, read from a script (tests/e2e/router.php wires it in): the file at {@see self::path()}
 * maps the exact text of a user message to the tool calls the model makes for it and the reply it gives after them.
 * A message without a script answers with its own text, so the spec fails where it was not written for.
 */
final class ScriptedCoach implements LlmClient
{
    public static function path(): string
    {
        return storage_path('framework/testing/e2e-coach.json');
    }

    public function supportsToolRounds(): bool
    {
        return true;
    }

    public function complete(array $messages, array $tools = []): array
    {
        $text = $this->lastUserText($messages);
        /** @var array<string, array{calls?: list<array{name: string, arguments: array<string, mixed>}>, reply?: string}> $script */
        $script = json_decode((string) @file_get_contents(self::path()), true) ?: [];
        $turn = $script[$text] ?? null;
        if ($turn === null) {
            return ['content' => "No script for: {$text}"];
        }

        $afterTools = (end($messages)['role'] ?? '') === 'tool';
        if ($afterTools || ($turn['calls'] ?? []) === []) {
            return ['content' => $turn['reply'] ?? 'Done.'];
        }

        return ['tool_calls' => array_map(
            fn (array $call, int $index): array => ['id' => 'e2e_tool_'.$index] + $call,
            $turn['calls'],
            array_keys($turn['calls']),
        )];
    }

    /** @param  list<array<string, mixed>>  $messages */
    private function lastUserText(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message['role'] !== 'user') {
                continue;
            }
            $content = $message['content'];

            return is_string($content) ? $content : implode('', array_map(fn (array $block): string => (string) ($block['text'] ?? ''), $content));
        }

        return '';
    }
}

<?php

namespace App\Ai;

use App\Agent\AgentStatus;
use Illuminate\Support\Facades\Log;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Throwable;

/**
 * The one AI call behind the food features: a prompt and the user's content in, an answer that
 * matches a JSON schema out. It goes through the package's LlmClient, the client the Coach uses,
 * so the key, model and retries are configured once (capabilities-ai.llm).
 *
 * The LlmClient has no structured-output option, so the schema is the input schema of a single
 * `answer` tool and the answer is that tool call's arguments, checked against the schema.
 */
final class AiJson
{
    public const TOOL = 'answer';

    private const ANSWER_BY_TOOL = 'Give your answer by calling the `answer` tool once. Do not answer in text.';

    public function __construct(
        private readonly AgentStatus $status,
        private readonly JsonSchemaValidator $validator,
    ) {}

    /**
     * @param  string  $prompt  file name in resources/prompts (without .md), sent as the system prompt
     * @param  list<array<string, mixed>>  $content  the user's content blocks (text, image)
     * @param  array<string, mixed>  $schema  the JSON schema the answer must match
     * @return array<string, mixed>
     *
     * @throws AiUnavailable when the server has no key; nothing is sent
     * @throws AiFailed when the call fails or the answer does not match the schema
     */
    public function ask(string $prompt, array $content, array $schema): array
    {
        if (! $this->status->enabled()) {
            throw new AiUnavailable;
        }

        try {
            // Resolved per call: capabilities that use this are built at boot, the binding and its config can change after.
            $reply = app(LlmClient::class)->complete([
                ['role' => 'system', 'content' => (string) file_get_contents(resource_path("prompts/{$prompt}.md"))],
                ['role' => 'system', 'content' => self::ANSWER_BY_TOOL],
                ['role' => 'user', 'content' => $content],
            ], [[
                'name' => self::TOOL,
                'description' => 'Give your answer.',
                'input_schema' => $schema,
            ]]);
        } catch (Throwable $e) {
            // The class only: the package's message quotes the provider's reply.
            Log::warning('The AI request failed.', ['prompt' => $prompt, 'exception' => $e::class]);
            throw new AiFailed;
        }

        foreach ($reply['tool_calls'] ?? [] as $call) {
            if (($call['name'] ?? null) !== self::TOOL) {
                continue;
            }
            $answer = $call['arguments'] ?? $call['input'] ?? null;
            if (is_array($answer) && $this->validator->validate($schema, $answer) === []) {
                return $answer;
            }

            break;
        }

        Log::warning('The AI did not answer through its schema.', ['prompt' => $prompt]);
        throw new AiFailed;
    }
}

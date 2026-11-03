<?php

namespace Tests\Agent;

use App\Agent\ConfirmationPolicy;
use App\Models\User;
use PHPUnit\Framework\Assert;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityData;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Domain\TurnRunner;
use Rawphp\CapabilitiesAi\Support\FakeLlmClient;

/**
 * One request of the eval set (AGT-050): a chat message to the Coach, and the tool calls it must make.
 *
 * In the gate the model is the fake driver, scripted to make exactly those calls, so a case proves they are
 * the right tools with arguments the app accepts. `php artisan agent:eval --live` sets AGENT_EVAL_LIVE and
 * the real model answers instead. A case then passes when the model's calls hold the expected ones, in
 * order, each with at least the expected arguments, and the app accepted each. Reads it makes on the way
 * do not count against it; a write nobody expected that the app accepts fails the case.
 */
final class Coach
{
    /**
     * @param  list<array{name: string, arguments: array<string, mixed>, ok: bool, result: array<string, mixed>}>  $calls
     */
    private function __construct(public readonly array $calls, public readonly string $reply) {}

    public static function live(): bool
    {
        return filter_var(env('AGENT_EVAL_LIVE', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Send the message as the user and check the Coach's answer.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $calls  tool and arguments, in order; each must succeed
     * @param  list<array{0: string, 1: array<string, mixed>}>  $refused  calls that must not succeed; the fake model tries each, after $calls
     * @param  array{0: string, 1: array<string, mixed>}|null  $asks  a destructive call the reply must ask the user to confirm (AGT-010), and must not make
     * @param  string  $reply  what the fake model says at the end
     * @param  list<array<string, mixed>>|null  $script  the fake model's rounds, in place of the ones made from the calls above
     */
    public static function says(User $user, string $message, array $calls = [], array $refused = [], ?array $asks = null, string $reply = 'Done.', ?array $script = null): self
    {
        config(['capabilities-ai.llm.driver' => 'anthropic']);
        if (self::live()) {
            config(['capabilities-ai.llm.anthropic.api_key' => env('ANTHROPIC_API_KEY')]);
            app()->forgetInstance(LlmClient::class);
            $model = app(LlmClient::class);
        } else {
            config(['capabilities-ai.llm.anthropic.api_key' => 'test-key']);
            $model = new FakeLlmClient($script ?? self::script($calls, $refused, $asks, $reply));
        }

        $recorder = new Recorder($model);
        app()->instance(LlmClient::class, $recorder);
        app()->forgetInstance(TurnRunner::class);

        test()->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => $message])->assertCreated();

        $turn = new self($recorder->calls(), $recorder->reply());
        $matched = $turn->assertMade($calls);
        $turn->assertNoOtherWrites($matched);
        $turn->assertNotMade($refused);
        if ($asks !== null) {
            $turn->assertAsks($asks[0], $asks[1]);
        }

        return $turn;
    }

    /** The reply's confirm line, which the user sends by itself to go ahead. */
    public function confirmLine(): string
    {
        foreach (preg_split('/\R/', $this->reply) ?: [] as $line) {
            if (str_starts_with(trim($line), 'confirm:')) {
                return trim($line);
            }
        }

        throw new \RuntimeException("The reply has no confirm line:\n{$this->reply}");
    }

    /**
     * The first successful call of a tool: the arguments the Coach sent, and the app's result.
     *
     * @return array{name: string, arguments: array<string, mixed>, ok: bool, result: array<string, mixed>}
     */
    public function call(string $name): array
    {
        foreach ($this->calls as $call) {
            if ($call['name'] === $name && $call['ok']) {
                return $call;
            }
        }

        throw new \RuntimeException("The Coach made no successful {$name} call.\n".$this->described());
    }

    /**
     * The app's result for the first successful call of a tool.
     *
     * @return array<string, mixed>
     */
    public function result(string $name): array
    {
        return $this->call($name)['result'];
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $calls
     * @param  list<array{0: string, 1: array<string, mixed>}>  $refused
     * @param  array{0: string, 1: array<string, mixed>}|null  $asks
     * @return list<array<string, mixed>>
     */
    private static function script(array $calls, array $refused, ?array $asks, string $reply): array
    {
        $round = array_map(fn (array $call): array => ['name' => $call[0], 'arguments' => self::example($call[1])], [...$calls, ...$refused]);
        if ($asks !== null) {
            $reply .= "\n".self::confirmFor($asks[0], $asks[1]);
        }

        return $round === [] ? [['content' => $reply]] : [['tool_calls' => $round], ['content' => $reply]];
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $expected
     * @return list<int> the index of the call that met each expectation
     */
    private function assertMade(array $expected): array
    {
        $matched = [];
        $from = 0;
        foreach ($expected as [$name, $arguments]) {
            $found = null;
            foreach (array_slice($this->calls, $from, null, true) as $index => $call) {
                if ($call['name'] === $name && $call['ok'] && self::matches($arguments, $call['arguments'])) {
                    $found = $index;
                    break;
                }
            }

            Assert::assertNotNull($found, "The Coach should call {$name} with ".self::json(self::example($arguments)).", and the app accept it.\n".$this->described());
            $matched[] = (int) $found;
            $from = (int) $found + 1;
        }

        return $matched;
    }

    /** @param  list<int>  $matched */
    private function assertNoOtherWrites(array $matched): void
    {
        $registry = app(CapabilityRegistry::class);
        $others = array_filter(
            $this->calls,
            fn (array $call, int $index): bool => $call['ok'] && ! in_array($index, $matched, true) && $registry->has($call['name']) && $registry->get($call['name'])->isMutating(),
            ARRAY_FILTER_USE_BOTH,
        );

        Assert::assertEmpty($others, 'The Coach changed something it was not asked to: '.implode(', ', array_column($others, 'name')).".\n".$this->described());
    }

    /** @param  list<array{0: string, 1: array<string, mixed>}>  $refused */
    private function assertNotMade(array $refused): void
    {
        foreach ($refused as [$name]) {
            $through = array_filter($this->calls, fn (array $call): bool => $call['name'] === $name && $call['ok']);

            Assert::assertEmpty($through, "No {$name} call may succeed in this turn.\n".$this->described());
        }
    }

    /** @param  array<string, mixed>  $input */
    private function assertAsks(string $name, array $input): void
    {
        $capability = app(CapabilityRegistry::class)->get($name);
        $policy = app(ConfirmationPolicy::class);
        // The policy reads a call's input through its input class, defaults and all, so the expected input goes the same way.
        $class = $capability->input;
        $call = is_string($class) && is_subclass_of($class, CapabilityData::class) ? $class::fromArray(self::example($input)) : self::example($input);
        $asked = array_filter(preg_split('/\R/', $this->reply) ?: [], fn (string $line): bool => $policy->confirms($capability, $call, $line));

        $this->assertNotMade([[$name, $input]]);
        Assert::assertNotEmpty($asked, 'The reply should end with the line `'.self::confirmFor($name, $input)."`. It said:\n{$this->reply}");
    }

    /** Whether the actual value holds the expected one: every expected key, lists item by item, numbers by value. */
    private static function matches(mixed $expected, mixed $actual): bool
    {
        if ($expected instanceof Any) {
            return true;
        }
        if (is_array($expected)) {
            if (! is_array($actual) || (array_is_list($expected) && count($expected) !== count($actual))) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $actual) || ! self::matches($value, $actual[$key])) {
                    return false;
                }
            }

            return true;
        }
        if (is_numeric($expected) && is_numeric($actual) && ! is_string($expected)) {
            return (float) $expected === (float) $actual;
        }

        return $expected === $actual;
    }

    /** The arguments with each made-up value replaced by its example. */
    private static function example(mixed $value): mixed
    {
        if ($value instanceof Any) {
            return $value->example;
        }

        return is_array($value) ? array_map(self::example(...), $value) : $value;
    }

    private function described(): string
    {
        $lines = array_map(fn (array $call): string => sprintf(
            '  %s %s → %s',
            $call['name'],
            self::json($call['arguments']),
            $call['ok'] ? 'ok' : 'refused: '.self::json($call['result']['error'] ?? $call['result']),
        ), $this->calls);

        return 'Its calls:'.($lines === [] ? ' none' : "\n".implode("\n", $lines))."\nIts reply: {$this->reply}";
    }

    /** @param  array<string, mixed>  $input */
    private static function confirmFor(string $name, array $input): string
    {
        return "confirm: {$name}".($input === [] ? '{}' : self::json(self::example($input)));
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

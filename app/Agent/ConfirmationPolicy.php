<?php

namespace App\Agent;

use App\Capabilities\Support\AgentPolicy;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\Capabilities\Support\CapabilityData;
use Throwable;

/**
 * CAP-020 step 5 (AGT-010): the agent may call a destructive capability only in a turn
 * whose user message is a single line, `confirm: <name><input JSON>`, whose input, read
 * through the capability's input class, is that call's input. The Coach writes the line at
 * the end of its question. Until the chat has a confirm control (V_COACH), which must send
 * exactly the Coach's line as the whole message, the user sends the line by itself; a pasted
 * copy of the Coach's reply is not consent. Key order, spacing inside the JSON and defaults
 * the model left out do not matter, because both inputs are compared as read keys. A line
 * split after the colon or inside the JSON is not one line and confirms nothing.
 */
final class ConfirmationPolicy implements AgentPolicy
{
    private const LINE = '/^confirm:[ \t]*([a-z][a-z0-9_]{0,63})(\{.*\})$/';

    public function __construct(private readonly TurnMessages $messages) {}

    public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool
    {
        if ($context->caller() !== 'agent' || ! HostDefinition::isDestructive($capability)) {
            return true;
        }

        $turn = $context->agent()['turn_ulid'] ?? null;
        $message = is_string($turn) ? $this->messages->forTurn($turn)['message'] ?? null : null;

        return $this->confirms($capability, $input, $message);
    }

    /** Whether the message, as a whole, is the confirm line for this call. */
    public function confirms(CapabilityDefinition $capability, mixed $input, ?string $message): bool
    {
        if ($message === null || (! is_array($input) && ! $input instanceof CapabilityData)) {
            return false;
        }
        if (preg_match(self::LINE, trim($message), $line) !== 1 || $line[1] !== $capability->name) {
            return false;
        }

        return $this->normalised($capability, $line[2]) === ReadKey::for($capability->name, $input);
    }

    private function normalised(CapabilityDefinition $capability, string $json): ?string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return null;
            }
            $class = $capability->input;

            return ReadKey::for($capability->name, is_string($class) && is_subclass_of($class, CapabilityData::class) ? $class::fromArray($decoded) : $decoded);
        } catch (Throwable) {
            return null;
        }
    }
}

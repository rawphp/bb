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
 * whose user message holds a line `confirm: <name><input as JSON>` for that exact call.
 * The Coach writes the line at the end of its question, and the user's reply carries it.
 * Both inputs are normalised through the capability's input class and compared as read
 * keys, so key order, spacing and defaults the model left out do not matter.
 */
final class ConfirmationPolicy implements AgentPolicy
{
    private const LINE = '/^\s*confirm:\s*([a-z][a-z0-9_]{0,63})(\{.*\})\s*$/m';

    public function __construct(private readonly TurnMessages $messages) {}

    public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool
    {
        if ($context->caller() !== 'agent' || ! HostDefinition::isDestructive($capability)) {
            return true;
        }

        $turn = $context->agent()['turn_ulid'] ?? null;
        $message = is_string($turn) ? $this->messages->userMessage($turn) : null;
        if ($message === null || (! is_array($input) && ! $input instanceof CapabilityData)) {
            return false;
        }

        $call = ReadKey::for($capability->name, $input);
        preg_match_all(self::LINE, $message, $lines, PREG_SET_ORDER);
        foreach ($lines as [, $name, $json]) {
            if ($name === $capability->name && $this->normalised($capability, $json) === $call) {
                return true;
            }
        }

        return false;
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

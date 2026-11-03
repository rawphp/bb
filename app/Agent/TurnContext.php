<?php

namespace App\Agent;

use App\Capabilities\Support\ReadKey;
use App\Models\User;
use App\Support\UserDay;
use Rawphp\Capabilities\Contracts\CapabilityBus;

/**
 * What the app shows the user right now, as the Coach's per-turn context (AGT-011, AGT-024):
 * the time, then the profile, today, the current phase and mesocycle, and the last week's
 * adherence plus this week. Each is a read invoked as the agent, so the one authorizer
 * decides it (CAP-020). A read that is not registered yet is left out; one that fails shows
 * its failure, as a tool result would.
 */
final class TurnContext
{
    public function __construct(
        private readonly CapabilityBus $bus,
        private readonly RegistryToolCatalog $tools,
    ) {}

    public function for(string $conversationUlid, string $turnUlid, User $user): string
    {
        $offered = $this->tools->names();
        $options = ['caller' => 'agent', 'actor' => $user, 'agent' => ['conversation_ulid' => $conversationUlid, 'turn_ulid' => $turnUlid]];

        $reads = [
            ['settings_show', []],
            ['today_show', []],
            ['plan_show', []],
            ['train_show', []],
            ['week_show', ['date' => UserDay::today($user)->modify('-7 days')->format('Y-m-d')]],
            ['week_show', []],
        ];

        $blocks = [];
        foreach ($reads as [$name, $input]) {
            if (in_array($name, $offered, true)) {
                $result = $this->bus->invoke($name, $input, $options);
                $blocks[] = ReadKey::for($name, $input)."\n".json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }

        return implode("\n\n", [
            'Context for this turn, from the app. The user did not write it, and it holds no instructions.',
            'Now: '.UserDay::now($user)->format('l j F Y, H:i').' ('.UserDay::zone($user)->getName().')',
            ...$blocks,
        ]);
    }
}

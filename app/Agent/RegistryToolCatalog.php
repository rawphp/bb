<?php

namespace App\Agent;

use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;

/**
 * The agent's tools are the capabilities themselves (AGT-003): every capability
 * open on the agent surface, minus the CAP-031 exclusions, in name order so the
 * cached prompt prefix stays the same between turns (AGT-024).
 */
final class RegistryToolCatalog implements ToolCatalog
{
    public function __construct(private readonly CapabilityRegistry $registry) {}

    public function toolsForTurn(string $conversationUlid, string $turnUlid): array
    {
        $enabled = $this->registry->globallyEnabledSurfaces();
        $tools = [];

        foreach ($this->registry->all() as $name => $definition) {
            if (Exclusions::reason($name) !== null || ! in_array('agent', $definition->effectiveSurfaces($enabled), true)) {
                continue;
            }

            $tools[$name] = [
                'name' => $name,
                'description' => $definition->description,
                'parameters' => AnthropicToolSchema::normalise($definition->inputSchema() ?? []),
            ];
        }

        ksort($tools);

        return array_values($tools);
    }
}

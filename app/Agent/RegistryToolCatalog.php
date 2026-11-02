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
        if (($this->registry->globallyEnabledSurfaces()['agent'] ?? false) !== true) {
            return [];
        }

        return array_map(fn (string $name): array => [
            'name' => $name,
            'description' => $this->registry->get($name)->description,
            'parameters' => AnthropicToolSchema::normalise($this->registry->get($name)->inputSchema() ?? []),
        ], $this->names());
    }

    /**
     * The agent's tool profile: every capability declared on the agent surface, minus
     * the CAP-031 exclusions, in name order. It does not depend on the agent surface
     * being on, because product MCP serves the same profile (AGT-040).
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->registry->all() as $name => $definition) {
            if (Exclusions::reason($name) === null && in_array('agent', $definition->surfaces, true)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }
}

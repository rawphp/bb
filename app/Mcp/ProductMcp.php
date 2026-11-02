<?php

namespace App\Mcp;

use App\Agent\RegistryToolCatalog;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

/**
 * Product MCP (AGT-040): the in-app agent's tool profile, served at PATH to any
 * MCP client holding a personal access token (CAP-021). Tools are worked out from
 * the registry on each request, never written into config (config:cache).
 */
final class ProductMcp
{
    public const PATH = '/mcp/bodybuilder';

    public function __construct(
        private readonly RegistryToolCatalog $catalog,
        private readonly CapabilityRegistry $registry,
    ) {}

    /** Whether the MCP surface is on (CAPABILITIES_SURFACE_MCP). */
    public function enabled(): bool
    {
        return ($this->registry->globallyEnabledSurfaces()['mcp'] ?? false) === true;
    }

    /**
     * The agent's tools that are open on the MCP surface, in name order.
     *
     * @return list<CapabilityTool>
     */
    public function tools(): array
    {
        $profile = $this->catalog->names();
        if ($profile === []) {
            return [];
        }

        $rows = $this->registry->mcpTools($profile);
        usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return array_map(fn (array $row): CapabilityTool => new CapabilityTool($row, $profile), $rows);
    }
}

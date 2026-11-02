<?php

namespace App\Agent;

use App\Capabilities\Support\HostDefinition;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

/**
 * The Coach's system prompt (AGT-024): the behaviour rules in resources/coach/instructions.md,
 * then the tools that need the user's say-so first (AGT-010), taken from the registry so the
 * list cannot drift from the capabilities tagged destructive. It holds no time and no user data,
 * so the cached prompt prefix is the same on every turn.
 */
final class CoachInstructions
{
    public function __construct(
        private readonly RegistryToolCatalog $tools,
        private readonly CapabilityRegistry $registry,
    ) {}

    public function text(): string
    {
        $destructive = array_values(array_filter(
            $this->tools->names(),
            fn (string $name): bool => HostDefinition::isDestructive($this->registry->get($name)),
        ));

        return trim((string) file_get_contents(resource_path('coach/instructions.md')))
            ."\n\n## Tools that need the user's say-so first\n\n"
            .($destructive === [] ? 'None.' : implode("\n", array_map(fn (string $name): string => "- {$name}", $destructive)));
    }
}

<?php

namespace App\Capabilities\Tokens;

use App\Capabilities\HostAuthorizer;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

/** The capabilities a token can be scoped to: every one on the http surface but the CAP-031 exclusions. */
final class TokenCatalog
{
    public function __construct(private readonly CapabilityRegistry $registry) {}

    /** @return array<string, CapabilityDefinition> sorted by name */
    public function callable(): array
    {
        $definitions = array_filter(
            $this->registry->all(),
            fn (CapabilityDefinition $definition): bool => in_array('http', $definition->surfaces, true)
                && HostAuthorizer::tokenMayCall($definition->name),
        );
        ksort($definitions);

        return $definitions;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->callable());
    }

    /** @return list<string> */
    public function reads(): array
    {
        return array_keys(array_filter($this->callable(), fn (CapabilityDefinition $definition): bool => $definition->readOnly));
    }
}

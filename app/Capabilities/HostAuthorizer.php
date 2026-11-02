<?php

namespace App\Capabilities;

use App\Agent\Exclusions;
use Illuminate\Contracts\Auth\Authenticatable;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * Decides every invoke on every channel (CAP-020).
 *
 * A signed-in actor must exist, and the agent may not call a CAP-031 exclusion
 * even if a call reaches the bus without the tool catalog. Resource ownership,
 * token scope and billing are added here as later steps.
 *
 * A create's client-made ULID is checked by UsesClientUlid instead: this
 * contract can only deny (403), and CAP-019 answers another user's ULID with 404.
 */
final class HostAuthorizer implements Authorizer
{
    public function authorize(string $capability, mixed $input, mixed $context): bool
    {
        return $context instanceof CapabilityContext
            && $context->user() instanceof Authenticatable
            && ($context->caller() !== 'agent' || Exclusions::reason($capability) === null);
    }
}

<?php

namespace App\Capabilities;

use Illuminate\Contracts\Auth\Authenticatable;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * Decides every invoke on every channel (CAP-020).
 *
 * Step 1 only: a signed-in actor must exist. Resource ownership, token scope,
 * billing and the agent policy are added here as later steps.
 */
final class HostAuthorizer implements Authorizer
{
    public function authorize(string $capability, mixed $input, mixed $context): bool
    {
        return $context instanceof CapabilityContext && $context->user() instanceof Authenticatable;
    }
}

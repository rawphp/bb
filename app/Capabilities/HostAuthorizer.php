<?php

namespace App\Capabilities;

use App\Agent\Exclusions;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * Decides every invoke on every channel (CAP-020).
 *
 * A signed-in actor must exist, and the agent may not call a CAP-031 exclusion
 * even if a call reaches the bus without the tool catalog. A personal access
 * token may call only the capabilities its abilities name (CAP-023), so a Health
 * bridge token reaches only the bridge's writes. The session is not limited.
 * Resource ownership and billing are added here as later steps.
 *
 * A create's client-made ULID is checked by UsesClientUlid instead: this
 * contract can only deny (403), and CAP-019 answers another user's ULID with 404.
 */
final class HostAuthorizer implements Authorizer
{
    public function authorize(string $capability, mixed $input, mixed $context): bool
    {
        if (! $context instanceof CapabilityContext) {
            return false;
        }

        $user = $context->user();

        return $user instanceof Authenticatable
            && ($context->caller() !== 'agent' || Exclusions::reason($capability) === null)
            && self::tokenAllows($user, $capability);
    }

    private static function tokenAllows(Authenticatable $user, string $capability): bool
    {
        $token = $user instanceof HasApiTokens ? $user->currentAccessToken() : null;

        return ! $token instanceof PersonalAccessToken || $token->can($capability);
    }
}

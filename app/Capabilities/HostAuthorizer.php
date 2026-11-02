<?php

namespace App\Capabilities;

use App\Agent\Exclusions;
use App\Capabilities\Support\AgentPolicy;
use App\Capabilities\Support\BillingGate;
use App\Capabilities\Support\Ownership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * Decides every invoke on every channel (CAP-020), in order:
 *  1. a signed-in actor exists, and the agent is not calling a CAP-031 exclusion
 *     (even if a call reaches the bus without the tool catalog);
 *  2. every row ID in the input belongs to the actor ({@see Ownership});
 *  3. a personal access token's scope allows the capability (CAP-023);
 *  4. billing ({@see BillingGate});
 *  5. the agent policy ({@see AgentPolicy}).
 *
 * A create's client-made ULID is checked by UsesClientUlid instead: this
 * contract can only deny (403), and CAP-019 answers another user's ULID with 404.
 */
final class HostAuthorizer implements Authorizer
{
    /** No token may call these: a token must not mint or revoke credentials (CAP-031). */
    public const SESSION_ONLY = ['token_create', 'token_revoke'];

    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Ownership $ownership,
        private readonly BillingGate $billing,
        private readonly AgentPolicy $agent,
    ) {}

    public function authorize(string $capability, mixed $input, mixed $context): bool
    {
        if (! $context instanceof CapabilityContext) {
            return false;
        }

        $user = $context->user();

        return $user instanceof User
            && ($context->caller() !== 'agent' || Exclusions::reason($capability) === null)
            && $this->ownership->allows($capability, $input, $user)
            && self::scopeAllows($capability, $user)
            && $this->billing->allows($user, $this->registry->get($capability))
            && $this->agent->allows($this->registry->get($capability), $input, $context);
    }

    /** A session, the in-app agent and jobs carry no token, so only a token is limited. */
    private static function scopeAllows(string $capability, User $user): bool
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return true;
        }

        return ! in_array($capability, self::SESSION_ONLY, true) && $token->can($capability);
    }
}

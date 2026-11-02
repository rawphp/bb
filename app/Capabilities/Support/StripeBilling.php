<?php

namespace App\Capabilities\Support;

use App\Domain\Billing\BillingRule;
use App\Models\Subscription;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinition;

/**
 * Billing on: what the user's Stripe subscription lets them call (SUB-022),
 * and whether they may talk to the Coach (SUB-024). {@see BillingRule} decides.
 */
final class StripeBilling implements BillingGate
{
    public function allows(User $user, CapabilityDefinition $capability): bool
    {
        // What needs no plan costs no subscription query.
        return BillingRule::isFree($capability->name, $capability->readOnly)
            || BillingRule::allows(Subscription::accessOf($user), $capability->name, $capability->readOnly);
    }

    public function allowsCoach(User $user): bool
    {
        return BillingRule::allowsCoach(Subscription::accessOf($user));
    }
}

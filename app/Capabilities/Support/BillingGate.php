<?php

namespace App\Capabilities\Support;

use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinition;

/**
 * CAP-020 step 4: billing (SUB-022). Bound to {@see NoBilling} until billing
 * (BILLING_API) binds its own, which blocks a lapsed user's writes.
 */
interface BillingGate
{
    public function allows(User $user, CapabilityDefinition $capability): bool;
}

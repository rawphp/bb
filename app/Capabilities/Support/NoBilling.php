<?php

namespace App\Capabilities\Support;

use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinition;

/** Billing off, as self-hosted: everything is allowed (SUB-023). */
final class NoBilling implements BillingGate
{
    public function allows(User $user, CapabilityDefinition $capability): bool
    {
        return true;
    }
}

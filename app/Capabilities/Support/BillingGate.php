<?php

namespace App\Capabilities\Support;

use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinition;

/**
 * CAP-020 step 4: billing (SUB-022). {@see NoBilling} with billing off;
 * {@see StripeBilling} with it on, which blocks a lapsed user's writes.
 */
interface BillingGate
{
    public function allows(User $user, CapabilityDefinition $capability): bool;

    /** Whether the user may start a Coach turn: the Coach is part of the plan (SUB-024). */
    public function allowsCoach(User $user): bool;
}

<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * "Start my plan" (ONB-020): builds the plan from the saved answers. Retries
 * carry the same Idempotency-Key (CAP-014).
 */
final class OnboardingCompleteInput extends CapabilityData {}

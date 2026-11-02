<?php

namespace App\Capabilities\Support;

/**
 * A capability that exists only with billing on (SUB-020). The host provider
 * skips it when BILLING_ENABLED is off, so a self-hosted instance has no
 * billing capability at all.
 */
interface BillingOnly {}

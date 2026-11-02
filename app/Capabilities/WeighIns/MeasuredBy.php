<?php

namespace App\Capabilities\WeighIns;

use App\Capabilities\Support\Channel;
use App\Enums\MeasurementSource;
use App\Models\User;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * WT-003, WT-020: where a weigh-in or a step count came from. The call came in on the Health bridge channel
 * (INT-001, CAP-022), as Activity records it, or the user typed it in.
 */
final class MeasuredBy
{
    public static function caller(CapabilityContext $context): MeasurementSource
    {
        $user = $context->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return Channel::derive($context->caller(), $token) === Channel::Bridge
            ? MeasurementSource::Shortcut
            : MeasurementSource::Manual;
    }
}

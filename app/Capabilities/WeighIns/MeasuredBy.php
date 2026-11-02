<?php

namespace App\Capabilities\WeighIns;

use App\Domain\Tokens\TokenScope;
use App\Enums\MeasurementSource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * WT-003, WT-020: where a weigh-in or a step count came from. The credential decides, not the transport: whatever
 * holds the Health bridge token (INT-001) posts as the bridge, over http or MCP alike, so it can never pass for the
 * user typing and overwrite a typed count. The audit's Channel records the transport, which is a different question.
 */
final class MeasuredBy
{
    public static function caller(CapabilityContext $context): MeasurementSource
    {
        $user = $context->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken && $token->scope === TokenScope::HealthBridge
            ? MeasurementSource::Shortcut
            : MeasurementSource::Manual;
    }
}

<?php

namespace App\Capabilities\WeighIns;

use App\Domain\Tokens\TokenScope;
use App\Enums\MeasurementSource;
use App\Models\PersonalAccessToken;
use App\Models\User;

/**
 * WT-003, WT-020: where a weigh-in or a step count came from. The Shortcuts bridge posts with the Health bridge
 * token (INT-001), so a call carrying that token is the bridge's; anything else is the user typing it in.
 */
final class MeasuredBy
{
    public static function caller(User $user): MeasurementSource
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->scope === TokenScope::HealthBridge
            ? MeasurementSource::Shortcut
            : MeasurementSource::Manual;
    }
}

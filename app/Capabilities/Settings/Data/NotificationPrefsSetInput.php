<?php

namespace App\Capabilities\Settings\Data;

use App\Capabilities\Support\SettingsValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** notification_prefs_set: one reminder type on or off (NOTIF-007). */
final class NotificationPrefsSetInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: SettingsValues::NOTIFICATION_KINDS)]
        public readonly string $kind,
        public readonly bool $on,
    ) {}
}

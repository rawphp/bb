<?php

namespace App\Capabilities\Reads\Data\Settings;

use App\Capabilities\Support\SettingsValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One reminder type's switch (NOTIF-007). */
final class NotificationSwitch extends CapabilityData
{
    public function __construct(
        #[Field(enum: SettingsValues::NOTIFICATION_KINDS)]
        public readonly string $kind,
        public readonly string $label,
        public readonly bool $on,
    ) {}
}

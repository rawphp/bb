<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Settings\ChoiceSetting;
use App\Capabilities\Reads\Data\Settings\DietFilters;
use App\Capabilities\Reads\Data\Settings\NotificationSwitch;
use App\Capabilities\Reads\Data\Settings\Profile;
use App\Capabilities\Reads\Data\Settings\Schedule;
use App\Capabilities\Reads\Data\Settings\Units;
use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** settings_show: everything Settings and its edit screens show (SET-001 to SET-011). */
final class SettingsShowOutput extends CapabilityData
{
    /**
     * @param  list<NotificationSwitch>  $notifications
     */
    public function __construct(
        #[Field(description: 'what the last save did to the targets (SET-004)')]
        public readonly ?Status $notice,
        public readonly Profile $profile,
        #[Field(description: 'the Re-check eligibility row (SAFE-017)')]
        public readonly Status $eligibility,
        public readonly Units $units,
        public readonly ChoiceSetting $appearance,
        public readonly ChoiceSetting $consumed_totals,
        public readonly DietFilters $diet_filters,
        public readonly bool $micro_plates,
        public readonly Schedule $schedule,
        #[Field(items: NotificationSwitch::class)]
        public readonly array $notifications,
        #[Field(description: 'the Health bridge row (INT-001)')]
        public readonly Status $health_bridge,
        #[Field(description: 'the Personal access tokens row (CAP-021)')]
        public readonly Status $tokens,
        #[Field(description: 'the Coach row; null when the agent is off (AGT-002)')]
        public readonly ?Status $coach,
        #[Field(description: 'the Subscription row; null when billing is off (SUB-020)')]
        public readonly ?Status $subscription,
        public readonly string $support_email,
        public readonly string $account_email,
        #[Field(description: 'true in local and testing only (SET-011)')]
        public readonly bool $developer,
    ) {}
}

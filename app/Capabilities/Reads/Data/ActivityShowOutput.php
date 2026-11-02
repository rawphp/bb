<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Settings\ActivityEntry;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** activity_show: the user's last 100 writes, newest first, with the channel (CAP-022). */
final class ActivityShowOutput extends CapabilityData
{
    /**
     * @param  list<ActivityEntry>  $entries
     */
    public function __construct(
        #[Field(items: ActivityEntry::class)]
        public readonly array $entries,
    ) {}
}

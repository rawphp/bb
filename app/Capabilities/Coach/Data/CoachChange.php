<?php

namespace App\Capabilities\Coach\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One write a Coach turn made, as a change card (AGT-013). */
final class CoachChange extends CapabilityData
{
    public function __construct(
        /** The audit record's id. */
        public readonly string $id,
        #[Field(description: 'the write\'s toast, or its capability name in words, as Activity titles it')]
        public readonly string $title,
        #[Field(description: 'the tab root the change shows on (ChangeScreens::TABS), or null')]
        public readonly ?string $screen,
        #[Field(description: 'the undo token while it can still be applied, else null')]
        public readonly ?string $undo,
    ) {}
}

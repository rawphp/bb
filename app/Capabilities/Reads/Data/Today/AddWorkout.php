<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Add workout sheet (SCH-032). */
final class AddWorkout extends CapabilityData
{
    public function __construct(
        public readonly ?AddWorkoutSession $session,
        #[Field(description: 'Why no session is offered: "No session left to move this week".')] public readonly ?string $session_message,
        public readonly ?ActivityDraft $activity,
    ) {}
}

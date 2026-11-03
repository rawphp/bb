<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\AwayCaloriesOption;
use App\Capabilities\Reads\Data\Today\AwayTrainingOption;
use App\Capabilities\Reads\Data\Today\AwayWeekOption;
use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Away sheet (SCH-060, SCH-061). */
final class AwayWeekShowOutput extends CapabilityData
{
    /**
     * @param  list<AwayWeekOption>  $weeks
     * @param  list<AwayTrainingOption>  $training_options
     * @param  list<AwayCaloriesOption>  $calorie_options
     * @param  list<Status>  $notes
     */
    public function __construct(
        #[Field(description: 'The week is away already.')] public readonly bool $is_editing,
        #[Field(items: AwayWeekOption::class)] public readonly array $weeks,
        #[Field(items: AwayTrainingOption::class)] public readonly array $training_options,
        #[Field(items: AwayCaloriesOption::class)] public readonly array $calorie_options,
        #[Field(description: 'What the sheet opens with.', enum: ['pause', 'deload'])] public readonly string $training,
        #[Field(description: 'Maintenance during Fat loss.', enum: ['rest', 'maintenance'])] public readonly string $calories,
        #[Field(description: '"Counts as a diet break" and the like.', items: Status::class)] public readonly array $notes,
        public readonly bool $shows_keep_phase_end,
        #[Field(description: 'What the keep-the-phase-end switch opens with: the week\'s saved value, false when it is not away.')] public readonly bool $keep_phase_end,
    ) {}
}

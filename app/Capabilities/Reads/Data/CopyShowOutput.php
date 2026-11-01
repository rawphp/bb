<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\CopyEntry;
use App\Capabilities\Reads\Data\Today\CopyMeal;
use App\Capabilities\Reads\Data\Today\CopyOptionDefaults;
use App\Capabilities\Reads\Data\Today\CopySource;
use App\Capabilities\Reads\Data\Today\CopyStripDay;
use App\Capabilities\Reads\Data\Today\CopyWorkout;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Copy sheet: the source, a week strip and the shown day's destinations (MEAL-060, SCH-034). */
final class CopyShowOutput extends CapabilityData
{
    /**
     * @param  list<CopyStripDay>  $strip
     * @param  list<CopyEntry>  $timeline
     * @param  list<CopyMeal>  $meals
     * @param  list<CopyWorkout>  $workouts
     */
    public function __construct(
        #[Field(enum: ['foods', 'day'])] public readonly string $mode,
        #[Field(description: '"Copy foods" or "Copy day"')] public readonly string $title,
        public readonly CopySource $source,
        #[Field(format: 'date')] public readonly string $shown_date,
        #[Field(items: CopyStripDay::class)] public readonly array $strip,
        #[Field(format: 'date')] public readonly ?string $previous_week,
        #[Field(format: 'date')] public readonly ?string $next_week,
        #[Field(items: CopyEntry::class)] public readonly array $timeline,
        #[Field(items: CopyMeal::class)] public readonly array $meals,
        #[Field(items: CopyWorkout::class)] public readonly array $workouts,
        public readonly CopyOptionDefaults $options,
    ) {}

    public static function rules(): array
    {
        return [
            'shown_date' => ['date_format:Y-m-d'],
            'previous_week' => ['nullable', 'date_format:Y-m-d'],
            'next_week' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}

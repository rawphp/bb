<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\CalendarRange;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Reads\Data\Today\StripDay;
use App\Capabilities\Reads\Data\Today\WeekAction;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The week strip above Today's timeline (SCH-020 to SCH-023, SCH-060, SCH-062, SCH-063). */
final class WeekShowOutput extends CapabilityData
{
    /**
     * @param  list<Status>  $pills
     * @param  list<StripDay>  $days
     * @param  list<WeekAction>  $actions
     * @param  list<Status>  $warnings
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $week_start,
        #[Field(description: '"WEEK 2"; null before the plan.')] public readonly ?string $heading,
        #[Field(description: '"Deload", "Away", "Rough week".', items: Status::class)] public readonly array $pills,
        #[Field(items: StripDay::class)] public readonly array $days,
        #[Field(description: 'A day of the week before; null at the plan\'s start.', format: 'date')] public readonly ?string $previous_week,
        #[Field(description: 'null at the plan\'s end.', format: 'date')] public readonly ?string $next_week,
        public readonly ?CalendarRange $calendar,
        #[Field(items: WeekAction::class)] public readonly array $actions,
        #[Field(description: 'SCH-062 "Too much cardio".', items: Status::class)] public readonly array $warnings,
    ) {}

    public static function rules(): array
    {
        return [
            'week_start' => ['date_format:Y-m-d'],
            'previous_week' => ['nullable', 'date_format:Y-m-d'],
            'next_week' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}

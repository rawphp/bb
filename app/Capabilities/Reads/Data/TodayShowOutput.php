<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\AddWorkout;
use App\Capabilities\Reads\Data\Today\DaySchedule;
use App\Capabilities\Reads\Data\Today\MissedSession;
use App\Capabilities\Reads\Data\Today\MissedWeeks;
use App\Capabilities\Reads\Data\Today\NextMesocycle;
use App\Capabilities\Reads\Data\Today\OffPlanEntry;
use App\Capabilities\Reads\Data\Today\ScheduleMenuItem;
use App\Capabilities\Reads\Data\Today\Steps;
use App\Capabilities\Reads\Data\Today\TimelineEntry;
use App\Capabilities\Reads\Data\Today\TodayBusy;
use App\Capabilities\Reads\Data\Today\TodayMeal;
use App\Capabilities\Reads\Data\Today\TodayOtherActivity;
use App\Capabilities\Reads\Data\Today\TodayWeighIn;
use App\Capabilities\Reads\Data\Today\TodayWorkout;
use App\Capabilities\Reads\Data\Today\Totals;
use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Today: one day's timeline and the controls around it (SCH-001 to SCH-011, SCH-030, SCH-057, SCH-058, DAY, MESO-060, ONB-031). */
final class TodayShowOutput extends CapabilityData
{
    /**
     * @param  list<Status>  $banners
     * @param  list<TimelineEntry>  $timeline
     * @param  list<TodayMeal>  $meals
     * @param  list<TodayWorkout>  $workouts
     * @param  list<TodayWeighIn>  $weigh_ins
     * @param  list<TodayOtherActivity>  $other_activities
     * @param  list<TodayBusy>  $busy_periods
     * @param  list<OffPlanEntry>  $off_plan
     * @param  list<ScheduleMenuItem>  $schedule_menu
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: '"Monday, Nov 2"')] public readonly string $title,
        #[Field(description: 'ONB-031 "Your plan starts Thu, Apr 23".', items: Status::class)] public readonly array $banners,
        #[Field(description: 'REV-001: the review card shows.')] public readonly bool $review_available,
        public readonly ?NextMesocycle $next_mesocycle,
        #[Field(description: 'null on a day with no target.')] public readonly ?Totals $totals,
        public readonly ?Steps $steps,
        #[Field(items: TimelineEntry::class)] public readonly array $timeline,
        #[Field(items: TodayMeal::class)] public readonly array $meals,
        #[Field(items: TodayWorkout::class)] public readonly array $workouts,
        #[Field(items: TodayWeighIn::class)] public readonly array $weigh_ins,
        #[Field(items: TodayOtherActivity::class)] public readonly array $other_activities,
        #[Field(items: TodayBusy::class)] public readonly array $busy_periods,
        #[Field(items: OffPlanEntry::class)] public readonly array $off_plan,
        #[Field(items: ScheduleMenuItem::class)] public readonly array $schedule_menu,
        #[Field(description: 'SCH-031: the time Add meal opens at, 24-hour "HH:MM"; null on a past day.')] public readonly ?string $add_meal_time,
        #[Field(description: 'null on a past day (NUT-024).')] public readonly ?AddWorkout $add_workout,
        #[Field(description: 'null before the profile exists.')] public readonly ?DaySchedule $schedule,
        public readonly ?MissedSession $missed_session,
        public readonly ?MissedWeeks $missed_weeks,
    ) {}
}

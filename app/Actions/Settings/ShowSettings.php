<?php

namespace App\Actions\Settings;

use App\Agent\AgentStatus;
use App\Capabilities\Reads\BillingShowCapability;
use App\Capabilities\Reads\Data\Settings\BodyFat;
use App\Capabilities\Reads\Data\Settings\BodyFatChoices;
use App\Capabilities\Reads\Data\Settings\BusyPeriod;
use App\Capabilities\Reads\Data\Settings\Choice;
use App\Capabilities\Reads\Data\Settings\ChoiceSetting;
use App\Capabilities\Reads\Data\Settings\DietFilters;
use App\Capabilities\Reads\Data\Settings\Height;
use App\Capabilities\Reads\Data\Settings\NotificationSwitch;
use App\Capabilities\Reads\Data\Settings\Profile as ProfileRow;
use App\Capabilities\Reads\Data\Settings\Schedule;
use App\Capabilities\Reads\Data\Settings\SelectableChoice;
use App\Capabilities\Reads\Data\Settings\StepRange;
use App\Capabilities\Reads\Data\Settings\TrainingDay;
use App\Capabilities\Reads\Data\Settings\Units;
use App\Capabilities\Reads\Data\Settings\Weekday;
use App\Capabilities\Reads\Data\Settings\WeekdayChip;
use App\Capabilities\Reads\Data\SettingsShowOutput;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Billing\Access;
use App\Domain\Defaults\TBL001BodyFatBuckets;
use App\Domain\Defaults\TBL001BodyFatBuckets\Row;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ConsumedTotals;
use App\Domain\Model\DietFilter;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Sex;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Notifications\ReminderKind;
use App\Domain\Onboarding\OnboardingText;
use App\Models\NotificationPref;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * settings_show (SET-001 to SET-011): every row of Settings and every value its edit screens start from, worded here
 * so the screen computes nothing. Ported from rp-clone's SettingsModel, HeightDisplay and AppearanceSettings; heights
 * and weekdays read as onboarding words them (OnboardingText).
 */
final class ShowSettings
{
    public const TARGETS_NOT_UPDATED = 'Your changes are saved, but your targets could not be updated. Tap Save to try again.';

    /** SET-005: the form's limits, as ScheduleDefaultsUpdateInput accepts them. */
    public const MEAL_COUNT_MIN = 2;

    public const MEAL_COUNT_MAX = 6;

    public const SESSION_MINUTES_MIN = 15;

    public const SESSION_MINUTES_MAX = 240;

    public const SESSION_MINUTES_STEP = 5;

    /** A training day added in Settings starts here. */
    public const NEW_TRAINING_DAY_START = '17:30';

    public const NEW_TRAINING_DAY_MINUTES = 60;

    /** A busy period added in Settings starts as this, on weekdays. */
    private const NEW_BUSY_PERIOD = ['label' => 'Busy', 'start' => '09:00', 'end' => '10:00', 'weekdays' => [1, 2, 3, 4, 5]];

    private const APPEARANCES = ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'];

    public function __construct(private readonly ShowHealthBridge $bridge) {}

    public function __invoke(User $user, CarbonImmutable $now): SettingsShowOutput
    {
        $profile = Profile::query()->where('user_id', $user->id)->first() ?? throw new DomainException(SaveProfile::NO_PROFILE);
        // PLAN-008: the day chosen last, so a change still pending shows at once.
        $plan = Plan::query()->where('user_id', $user->id)->with('reviewDayChanges')->first();
        $reviewWeekday = $plan?->reviewDayChanges->last()?->review_weekday ?? $plan?->review_weekday;

        return new SettingsShowOutput(
            notice: $profile->targets_rebuild_failed_at === null ? null : new Status(self::TARGETS_NOT_UPDATED, Tone::Warning->value),
            profile: self::profile($profile),
            eligibility: $profile->training_only
                ? new Status('Training only', Tone::Info->value)
                : new Status('Training and nutrition', Tone::Good->value),
            units: new Units(
                weight: self::setting($profile->weight_unit, array_map(fn (WeightUnit $unit) => new Choice($unit->value, $unit->value), WeightUnit::cases())),
                height: self::setting($profile->height_unit, [new Choice(HeightUnit::Cm->value, 'cm'), new Choice(HeightUnit::FeetInches->value, 'ft and in')]),
            ),
            appearance: self::setting($profile->appearance, array_map(
                fn (string $value, string $label) => new Choice($value, $label), array_keys(self::APPEARANCES), self::APPEARANCES,
            )),
            consumed_totals: self::setting($profile->consumed_totals, array_map(
                fn (ConsumedTotals $totals) => new Choice($totals->value, $totals->title()), ConsumedTotals::cases(),
            )),
            diet_filters: self::dietFilters(TodaySnapshot::json($profile->diet_filters)),
            micro_plates: $profile->micro_plates,
            schedule: self::schedule($profile, $reviewWeekday === null ? null : (int) $reviewWeekday),
            notifications: self::notifications($user),
            health_bridge: ($this->bridge)($user, $now)->status,
            tokens: self::tokens($user, $now),
            // Resolved per call, as agent_status_show does: the config can change after this action is built.
            coach: app(AgentStatus::class)->enabled() ? ($user->coach_on ? new Status('On', Tone::Good->value) : new Status('Off', Tone::Neutral->value)) : null,
            subscription: config('billing.enabled') ? self::subscription($user) : null,
            support_email: (string) config('app.support_email'),
            account_email: $user->email,
            developer: app()->environment('local', 'testing'),
        );
    }

    private static function profile(Profile $profile): ProfileRow
    {
        $sex = Sex::from($profile->sex);
        $bucket = BodyFatBucket::tryFrom((string) $profile->body_fat_bucket) ?? BodyFatBucket::Low;
        $buckets = fn (Sex $sex) => array_map(fn (Row $row) => new Choice($row->bucket->value, $row->label), TBL001BodyFatBuckets::rows($sex));
        $text = '';
        foreach (TBL001BodyFatBuckets::rows($sex) as $row) {
            $text = $row->bucket === $bucket ? $row->label : $text;
        }

        return new ProfileRow(
            name: (string) $profile->name,
            sex: self::setting($sex->value, [new Choice(Sex::Male->value, 'Male'), new Choice(Sex::Female->value, 'Female')]),
            birth_date: PlanSnapshot::day($profile->birth_date)->format('Y-m-d'),
            birth_date_text: PlanSnapshot::day($profile->birth_date)->format('j F Y'),
            height: self::height((float) $profile->height_cm, HeightUnit::from($profile->height_unit)),
            body_fat: new BodyFat($bucket->value, $text, new BodyFatChoices(male: $buckets(Sex::Male), female: $buckets(Sex::Female))),
            uses_glp1: (bool) $profile->uses_glp1,
            experience: self::setting($profile->experience, array_map(
                fn (ExperienceLevel $level) => new Choice($level->value, ucfirst($level->value)), ExperienceLevel::cases(),
            )),
        );
    }

    /** The text, and the numbers the form edits in the user's unit: cm, or feet and inches to a tenth. */
    private static function height(float $cm, HeightUnit $unit): Height
    {
        if ($unit === HeightUnit::Cm) {
            return new Height($unit->value, OnboardingText::heightText($cm, $unit), round($cm, 1), null, null);
        }
        [$feet, $inches] = UnitConversion::cmToFeetInches($cm);
        $inches = round($inches, 1);
        if ($inches >= UnitConversion::INCHES_PER_FOOT) {
            $feet++;
            $inches = 0.0;
        }

        return new Height($unit->value, OnboardingText::heightText($cm, $unit), null, $feet, $inches);
    }

    /** @param  list<Choice>  $choices */
    private static function setting(string $value, array $choices): ChoiceSetting
    {
        return new ChoiceSetting($value, $choices);
    }

    /**
     * SET-006: "None", the one filter's name, or how many are on.
     *
     * @param  array<array-key, mixed>  $on  the stored filter values
     */
    private static function dietFilters(array $on): DietFilters
    {
        $chosen = array_values(array_filter(DietFilter::cases(), fn (DietFilter $filter) => in_array($filter->value, $on, true)));

        return new DietFilters(
            summary: match (count($chosen)) {
                0 => 'None',
                1 => $chosen[0]->title(),
                default => count($chosen).' on',
            },
            choices: array_map(
                fn (DietFilter $filter) => new SelectableChoice($filter->value, $filter->title(), in_array($filter, $chosen, true)),
                DietFilter::cases(),
            ),
        );
    }

    private static function schedule(Profile $profile, ?int $reviewWeekday): Schedule
    {
        $window = TodaySnapshot::json($profile->eating_window);
        $steps = [];
        foreach (TodaySnapshot::json($profile->step_plan) as $range) {
            $steps[(int) $range['weekday']] = $range;
        }

        return new Schedule(
            eating_window_start: (string) ($window['start'] ?? ''),
            eating_window_end: (string) ($window['end'] ?? ''),
            meal_count: (int) $profile->meal_count,
            meal_count_min: self::MEAL_COUNT_MIN,
            meal_count_max: self::MEAL_COUNT_MAX,
            weekdays: array_map(fn (int $day) => new Weekday($day, OnboardingText::weekdayFullName($day), OnboardingText::weekdayName($day)), range(1, 7)),
            training_days: array_map(fn (array $day) => new TrainingDay(
                weekday: (int) $day['weekday'], label: OnboardingText::weekdayFullName((int) $day['weekday']), start: (string) $day['start'], minutes: (int) $day['minutes'],
            ), TodaySnapshot::json($profile->training_days)),
            new_training_day_start: self::NEW_TRAINING_DAY_START,
            new_training_day_minutes: self::NEW_TRAINING_DAY_MINUTES,
            session_minutes_min: self::SESSION_MINUTES_MIN,
            session_minutes_max: self::SESSION_MINUTES_MAX,
            session_minutes_step: self::SESSION_MINUTES_STEP,
            busy_periods: array_map(fn (array $period) => self::busyPeriod($period), TodaySnapshot::json($profile->busy_periods)),
            new_busy_period: self::busyPeriod(self::NEW_BUSY_PERIOD),
            step_plan: array_map(fn (int $day) => new StepRange(
                weekday: $day,
                label: OnboardingText::weekdayFullName($day),
                min: isset($steps[$day]) ? (int) $steps[$day]['min'] : null,
                max: isset($steps[$day]) ? (int) $steps[$day]['max'] : null,
            ), range(1, 7)),
            review_weekday: $reviewWeekday,
        );
    }

    /** @param  array{label: string, start: string, end: string, weekdays: list<int>}  $period */
    private static function busyPeriod(array $period): BusyPeriod
    {
        $on = array_map(intval(...), $period['weekdays']);

        return new BusyPeriod(
            label: $period['label'],
            start: $period['start'],
            end: $period['end'],
            days: array_map(fn (int $day) => new WeekdayChip($day, OnboardingText::weekdayName($day), in_array($day, $on, true)), range(1, 7)),
        );
    }

    /** @return list<NotificationSwitch> NOTIF-007: each switch, from the defaults until the user changes it */
    private static function notifications(User $user): array
    {
        $switches = NotificationPref::switches($user);

        return array_map(
            fn (ReminderKind $kind) => new NotificationSwitch($kind->value, $kind->title(), $switches->isOn($kind)),
            ReminderKind::cases(),
        );
    }

    /** CAP-021: how many of the user's tokens still work. */
    private static function tokens(User $user, CarbonImmutable $now): Status
    {
        $active = PersonalAccessToken::ownedBy($user)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->count();

        return new Status($active === 0 ? 'None' : "{$active} active", Tone::Neutral->value);
    }

    /** SET-007: the subscription's status, as billing_show words it. */
    private static function subscription(User $user): Status
    {
        $subscription = Subscription::of($user);

        return BillingShowCapability::status(Access::fromStripeStatus($subscription?->status), $subscription);
    }
}

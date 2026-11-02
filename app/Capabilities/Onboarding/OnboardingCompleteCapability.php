<?php

namespace App\Capabilities\Onboarding;

use App\Actions\Onboarding\StartPlan;
use App\Capabilities\HostCapability;
use App\Capabilities\Onboarding\Data\OnboardingCompleteInput;
use App\Capabilities\Plan\PlanReads;
use App\Capabilities\Reads\Today\TodayWords;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\TodayReads;
use App\Domain\Onboarding\OnboardingStart;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OnboardingCompleteCapability implements HostCapability
{
    /**
     * CAP-011, from what the start writes: the draft (onboarding_show); the plan, phase, mesocycle and plan week
     * (PlanReads); week one's day targets and meals (Today's sheets, Progress); the profile (Progress, the templates'
     * filters, the eligibility re-check). settings_show shows the profile too, but is not registered yet (#90).
     */
    public const AFFECTS = [
        'onboarding_show', ...PlanReads::AFFECTS, 'day_adjust_show', 'day_redistribute_show', 'progress_show',
        'templates_show', 'eligibility_recheck_show',
    ];

    public function __construct(private readonly StartPlan $start) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('onboarding_complete', affects: self::AFFECTS, destructive: true)
            ->description('Start the plan from the saved setup answers: it saves the profile, the plan and its first phase, the first mesocycle, the first week\'s calorie and meal targets and the weigh-in schedule, then deletes the answers. Use it when the user presses "Start my plan" on the setup review. It refuses once the user has a plan, and while any step\'s answers cannot continue, with that step\'s continue hint or a general message that answers are missing.')
            ->cli('onboarding', 'complete')
            ->input(OnboardingCompleteInput::class)
            ->run(function (OnboardingCompleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $start = ($this->start)($user);

                return new WriteResult(
                    invalidates: self::keys($user, $start),
                    toast: 'Your plan starts '.TodayWords::shortDate(TrainSnapshot::day($start->plan['start_date'])),
                );
            });
    }

    /** @return list<string> */
    private static function keys(User $user, OnboardingStart $start): array
    {
        $dates = $start->weekDates;

        return array_values(array_unique([
            ReadKey::for('onboarding_show'),
            ...PlanReads::keys($user, $start->phase['id']),
            ReadKey::for('mesocycle_show', ['mesocycle_id' => $start->mesocycle['id']]),
            ...TodayReads::keys($dates, ['day_adjust_show', 'day_redistribute_show']),
            ...TodayReads::weekKeys($dates),
            ReadKey::for('progress_show'),
            ReadKey::for('progress_show', ['scope' => 'phase']),
            ReadKey::for('progress_show', ['scope' => 'plan']),
            ReadKey::for('templates_show'),
            ReadKey::for('eligibility_recheck_show'),
        ]));
    }
}

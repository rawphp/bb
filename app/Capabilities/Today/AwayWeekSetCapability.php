<?php

namespace App\Capabilities\Today;

use App\Actions\Today\SetAwayWeeks;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Plan\PlanReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\AwayWeekSetInput;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class AwayWeekSetCapability implements HostCapability
{
    public function __construct(private readonly SetAwayWeeks $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // SCH-060, PLAN-025: a diet break moves the phase end date, so the phases, the mesocycles and Today change too.
        return HostDefinition::write('away_week_set', affects: ['today_show', 'week_show', 'away_week_show', 'plan_show', 'phase_show', 'train_show', 'mesocycle_show'], reversible: true)
            ->description('Mark plan weeks away, change them, or mark them not away; only the weeks the Away sheet offers are accepted, from next week to the end of the phase. An away week pauses training or becomes the deload, and eats at rest-day targets or at maintenance; in Fat loss maintenance counts as a diet break, which moves the phase end date a week later unless keep_phase_end is true. Left out, keep_phase_end is what each week was saved with.')
            ->cli('week', 'away-set')
            ->input(AwayWeekSetInput::class)
            ->run(function (AwayWeekSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $saved = ($this->set)($user, $input);

                return new WriteResult(
                    invalidates: array_values(array_unique([
                        ...TodayReads::keys($saved['dates']),
                        ...TodayReads::weekKeys($saved['dates']),
                        ...array_map(fn (string $start) => ReadKey::for('away_week_show', ['week_start' => $start]), $saved['offered']),
                        // PLAN-025: a diet break changes every phase's target weight, and moves their dates.
                        ...PlanReads::everyPhaseKeys($user),
                        // SCH-060: a paused week moves the mesocycles around it.
                        ...MesocycleReads::keys(...Mesocycle::savedBy($user)->pluck('id')->map(fn ($id) => (string) $id)->all()),
                    ])),
                    toast: $saved['toast'],
                );
            });
    }
}

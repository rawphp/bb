<?php

namespace App\Capabilities\Today;

use App\Actions\Today\OptimizeDay;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\DayOptimizeInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class DayOptimizeCapability implements HostCapability
{
    public function __construct(private readonly OptimizeDay $optimize) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // SCH-056, UI-010: a re-target shows a toast with Undo.
        return HostDefinition::write('day_optimize', affects: ['today_show', 'week_show', ...TodayReads::SHEETS], reversible: true)
            ->description('Save Optimize schedule for a day, from today on: its unchecked meals move to the times day_optimize_show previews, around the workout and inside the eating window, and share the same day total. Use it after the user has seen the preview. A day with nothing to move is refused.')
            ->cli('day', 'optimize')
            ->input(DayOptimizeInput::class)
            ->run(function (DayOptimizeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $optimized = ($this->optimize)($user, $input);

                return new WriteResult(invalidates: TodayReads::forDays($optimized['dates'], $optimized['week']), toast: $optimized['toast']);
            });
    }
}

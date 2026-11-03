<?php

namespace App\Capabilities\Today;

use App\Actions\Today\CopyDay;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\DayCopyInput;
use App\Domain\Copy\CopyOptions;
use App\Models\DayTargetMeal;
use App\Models\User;
use App\Support\Today\DayPlans;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class DayCopyCapability implements HostCapability
{
    public function __construct(private readonly CopyDay $copy) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, CAP-013: a copy shows a toast with Undo.
        return HostDefinition::write('day_copy', affects: CopyReads::AFFECTS, reversible: true)
            ->description('Copy every planned food and recipe serving of a day to other days of the plan, meal 1 to meal 1 by time order; extra meals on either side are left alone. It takes the same three options as meal_copy, and skips checked-in meals, empty source meals, the source day and days without targets.')
            ->cli('day', 'copy')
            ->input(DayCopyInput::class)
            ->run(function (DayCopyInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $written = ($this->copy)(
                    $user,
                    $input->source_date,
                    $input->target_dates,
                    new CopyOptions($input->update_target_macros, $input->update_meal_times, $input->copy_locked_state),
                );
                $days = array_unique(array_map(fn (DayTargetMeal $meal) => DayPlans::day($meal->dayTarget->date)->format('Y-m-d'), $written));

                return new WriteResult(invalidates: CopyReads::keys($user, $written), toast: CopyReads::toast(count($days), 'day'));
            });
    }
}

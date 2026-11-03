<?php

namespace App\Capabilities\Today;

use App\Actions\Today\CopyMeals;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\CopyShowInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\MealCopyInput;
use App\Domain\Copy\CopyOptions;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealCopyCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly CopyMeals $copy) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, CAP-013: a copy shows a toast with Undo.
        return HostDefinition::write('meal_copy', affects: CopyReads::AFFECTS, reversible: true)
            ->description('Copy a meal\'s planned foods and recipe servings into other meals on any days, replacing what those meals hold. By default the copies re-fit to each meal\'s own targets with locked amounts kept, keep each meal\'s time and arrive unlocked; update_target_macros, update_meal_times and copy_locked_state change that. Checked-in meals, meals on a day before the plan and the source itself are skipped (copy_show lists the meals that can take the copy).')
            ->cli('meal', 'copy')
            ->input(MealCopyInput::class)
            ->run(function (MealCopyInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $written = ($this->copy)(
                    $user,
                    array_map(fn (string $id) => [$input->meal_id, $id], $input->target_meal_ids),
                    new CopyOptions($input->update_target_macros, $input->update_meal_times, $input->copy_locked_state),
                );

                return new WriteResult(
                    // The sheet as it was opened lists the destinations' foods.
                    invalidates: [...CopyReads::keys($user, $written), ReadKey::for('copy_show', new CopyShowInput(meal_id: $input->meal_id))],
                    toast: CopyReads::toast(count($written), 'meal'),
                );
            });
    }

    public function owned(User $user): array
    {
        return [
            'meal_id' => DayTargetMeal::query()->liveOf($user),
            'target_meal_ids' => DayTargetMeal::query()->liveOf($user),
        ];
    }
}

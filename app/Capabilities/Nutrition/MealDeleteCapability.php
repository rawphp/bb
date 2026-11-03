<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\DeleteMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealDeleteInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteMeal $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: a delete needs the user's say-so before the Coach makes it. CAP-013: undo brings the meal back whole.
        return HostDefinition::write('meal_delete', affects: MealReads::AFFECTS, reversible: true, destructive: true)
            ->description('Delete a meal from its day, with its planned foods, recipe servings, check-in and planning state. The day\'s other meals keep their targets; nothing is re-targeted. Use it when the user will not eat that meal.')
            ->cli('meal', 'delete')
            ->input(MealDeleteInput::class)
            ->run(function (MealDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                // The other meals' screens change too: a "Meal n" title or the pre- or post-workout tag can move.
                $keys = array_merge(...array_map(MealReads::keys(...), ($this->delete)($user, $input->meal_id)));

                return new WriteResult(invalidates: array_values(array_unique($keys)));
            });
    }

    public function owned(User $user): array
    {
        return ['meal_id' => DayTargetMeal::query()->liveOf($user)];
    }
}

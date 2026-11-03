<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\SetMealPlanningState;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealPlanningDoneInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealPlanningDoneCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetMealPlanningState $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('meal_planning_done', affects: MealReads::AFFECTS, reversible: true)
            ->description('Mark a meal "Done planning foods" (done true) or "Still planning foods" (done false). A meal still planning whose foods miss its targets shows the "Adjust foods" badge and cannot be checked in with one tap; marking it done removes the badge, so it can be. Its foods and targets do not change.')
            ->cli('meal', 'planning-done')
            ->input(MealPlanningDoneInput::class)
            ->run(function (MealPlanningDoneInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: MealReads::keys(($this->set)($user, $input->meal_id, $input->done)));
            });
    }

    public function owned(User $user): array
    {
        return ['meal_id' => DayTargetMeal::query()->liveOf($user)];
    }
}

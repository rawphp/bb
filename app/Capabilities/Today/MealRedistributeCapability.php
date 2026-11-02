<?php

namespace App\Capabilities\Today;

use App\Actions\Today\RedistributeMeals;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\MealRedistributeInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealRedistributeCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly RedistributeMeals $redistribute) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, CAP-013: a redistribution shows a toast with Undo.
        return HostDefinition::write('meal_redistribute', affects: ['day_redistribute_show', 'day_adjust_show'], reversible: true)
            ->description('Spread what is left of a day\'s calories and macros over the chosen later meals, after food eaten off-plan or a meal that went over. The day\'s totals stay; checked-in, past and fully locked meals cannot take a share (day_redistribute_show lists the ones that can). Chosen meals that already have foods are marked still planning until their foods are re-fitted.')
            ->cli('day', 'redistribute')
            ->input(MealRedistributeInput::class)
            ->run(function (MealRedistributeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $chosen = ($this->redistribute)($user, $input->date, $input->meal_ids);

                return new WriteResult(
                    invalidates: TodayReads::keys([$input->date], ['day_redistribute_show', 'day_adjust_show']),
                    toast: count($chosen) === 1 ? "{$chosen[0]->name} re-targeted" : count($chosen).' meals re-targeted',
                );
            });
    }

    public function owned(User $user): array
    {
        return ['meal_ids' => DayTargetMeal::query()->where('user_id', $user->id)];
    }
}

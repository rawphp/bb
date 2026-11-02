<?php

namespace App\Capabilities\Today;

use App\Actions\Today\AdjustDay;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\DayAdjustInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class DayAdjustCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly AdjustDay $adjust) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('day_adjust', affects: ['day_adjust_show', 'day_redistribute_show'])
            ->description('Move calories between two meals of a day, or between the day and another day of its plan week, through carbs so protein and fat hold. Use it when the user wants to eat less on one day or meal and more on another; finished days, the 80% to 125% band and the fat and carb floors are refused in plain words. Check the move with day_adjust_show first.')
            ->cli('day', 'adjust')
            ->input(DayAdjustInput::class)
            ->run(function (DayAdjustInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $adjusted = ($this->adjust)($user, $input);

                return new WriteResult(
                    invalidates: [
                        ...TodayReads::keys($adjusted['dates'], ['day_adjust_show', 'day_redistribute_show']),
                        // NUT-021: a move between days shows on the week strip.
                        ...(count($adjusted['dates']) > 1 ? TodayReads::weekKeys($adjusted['week']) : []),
                    ],
                    toast: "Moved {$input->kcal} kcal",
                );
            });
    }

    public function owned(User $user): array
    {
        $meals = DayTargetMeal::query()->where('user_id', $user->id);

        return ['from_meal_id' => $meals, 'to_meal_id' => clone $meals];
    }
}

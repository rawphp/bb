<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\DayAdjustShowInput;
use App\Capabilities\Reads\Data\DayAdjustShowOutput;
use App\Capabilities\Reads\Today\DayAdjustPayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use App\Support\Today\AdjustWeek;
use App\Support\UserDay;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class DayAdjustShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('day_adjust_show')
            ->description('Show the Adjust sheet for a day with a calorie move worked out: the meals and other days of the plan week it can move between, the projected calories and protein, and why a move is refused. Use it to check a move before day_adjust saves it. It changes nothing.')
            ->cli('day', 'adjust-show')
            ->input(DayAdjustShowInput::class)
            ->output(DayAdjustShowOutput::class)
            ->run(function (DayAdjustShowInput $input, CapabilityContext $context): DayAdjustShowOutput {
                /** @var User $user */
                $user = $context->user();

                return DayAdjustPayload::build($input, AdjustWeek::load($user, $input->date), UserDay::today($user));
            });
    }

    public function owned(User $user): array
    {
        $meals = DayTargetMeal::query()->where('user_id', $user->id);

        return ['from_meal_id' => $meals, 'to_meal_id' => clone $meals];
    }
}

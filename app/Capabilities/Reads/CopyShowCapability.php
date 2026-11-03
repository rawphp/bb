<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\CopyShowInput;
use App\Capabilities\Reads\Data\CopyShowOutput;
use App\Capabilities\Reads\Today\CopyPayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class CopyShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('copy_show')
            ->description('Show the Copy sheet: copy a meal\'s foods (meal_id) or a whole day (source_date), with the week strip around shown_date and the days that can take the copy. Copying a meal also lists the shown day\'s meals in time order with the workout between them, and which cannot take the copy (the source, checked-in meals). Use it before meal_copy or day_copy; it changes nothing.')
            ->cli('meal', 'copy-show')
            ->input(CopyShowInput::class)
            ->output(CopyShowOutput::class)
            ->run(function (CopyShowInput $input, CapabilityContext $context): CopyShowOutput {
                /** @var User $user */
                $user = $context->user();
                $sourceDay = $input->meal_id === null
                    ? TrainSnapshot::day((string) $input->source_date)
                    : TrainSnapshot::day(DayTargetMeal::query()->liveOf($user)->with('dayTarget')->findOrFail($input->meal_id)->dayTarget->date);
                $source = TodaySnapshot::load($user, $sourceDay->format('Y-m-d'));
                $shown = $input->shown_date === null ? $source : TodaySnapshot::load($user, $input->shown_date);

                return CopyPayload::build($source, $sourceDay, $shown, $input->shown_date === null ? $sourceDay : $shown->date, $input->meal_id);
            });
    }

    public function owned(User $user): array
    {
        return ['meal_id' => DayTargetMeal::query()->liveOf($user)];
    }
}

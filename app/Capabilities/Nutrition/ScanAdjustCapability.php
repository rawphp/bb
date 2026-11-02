<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Scan\ScanMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ScanAdjustInput;
use App\Capabilities\Nutrition\Data\ScanAdjustOutput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\Food;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ScanAdjustCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('scan_adjust')
            ->description('Recalculate a scanned meal after the user edits it: a new portion size, a typed amount, a food matched to another one, or a row removed. Send the rows as scan_photo, scan_describe or this returned them, with any edits, and the portion they are at. It saves nothing.')
            ->cli('scan', 'adjust')
            ->input(ScanAdjustInput::class)
            ->output(ScanAdjustOutput::class)
            ->run(function (ScanAdjustInput $input, CapabilityContext $context): ScanAdjustOutput {
                /** @var User $user */
                $user = $context->user();

                return ScanDrafts::of(ScanAdjustOutput::class, app(ScanMeal::class)->adjust(
                    $user, $input->rows, $input->from_portion, $input->portion, $input->saved_meal_name,
                ));
            });
    }

    public function owned(User $user): array
    {
        return ['rows.*.food_id' => Food::query()->visibleTo($user)];
    }
}

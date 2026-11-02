<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Scan\ScanMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ScanPhotoInput;
use App\Capabilities\Nutrition\Data\ScanPhotoOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ScanPhotoCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('scan_photo')
            ->description('Estimate each food on a plate and its grams from a photo, matched to database foods, as an editable list with its totals. Use it when the user photographs a meal to log it; add the matched foods with meal_food_add or keep them with saved_meal_save. It saves nothing.')
            ->cli('scan', 'photo')
            ->input(ScanPhotoInput::class)
            ->output(ScanPhotoOutput::class)
            ->run(function (ScanPhotoInput $input, CapabilityContext $context): ScanPhotoOutput {
                /** @var User $user */
                $user = $context->user();

                // Resolved per call: capabilities are built at boot, and the AI key the call checks can change after.
                return ScanDrafts::of(ScanPhotoOutput::class, app(ScanMeal::class)->photo($user, $input->image));
            });
    }
}

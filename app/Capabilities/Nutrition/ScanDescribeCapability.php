<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Scan\ScanMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ScanDescribeInput;
use App\Capabilities\Nutrition\Data\ScanDescribeOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ScanDescribeCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('scan_describe')
            ->description('Estimate each food and its grams from a typed description of a meal, such as "chicken burrito bowl with rice", matched to database foods, as the same editable list a photo gives. Use it when the user describes what they ate instead of photographing it. It saves nothing.')
            ->cli('scan', 'describe')
            ->input(ScanDescribeInput::class)
            ->output(ScanDescribeOutput::class)
            ->run(function (ScanDescribeInput $input, CapabilityContext $context): ScanDescribeOutput {
                /** @var User $user */
                $user = $context->user();

                // Resolved per call: capabilities are built at boot, and the AI key the call checks can change after.
                return ScanDrafts::of(ScanDescribeOutput::class, app(ScanMeal::class)->describe($user, $input->description));
            });
    }
}

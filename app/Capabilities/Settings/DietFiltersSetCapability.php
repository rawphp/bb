<?php

namespace App\Capabilities\Settings;

use App\Actions\Settings\SetDietFilters;
use App\Capabilities\HostCapability;
use App\Capabilities\Settings\Data\DietFiltersSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class DietFiltersSetCapability implements HostCapability
{
    public function __construct(private readonly SetDietFilters $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('diet_filters_set', affects: ['settings_show', 'food_search'])
            ->description('Set which diet filters are on (dairy free, gluten free, grain free, low FODMAP, ovo-lacto vegetarian, paleo, vegan), sending every filter that should be on; an empty list turns them all off. Food search then hides any food that does not match every filter that is on. It does not change the targets.')
            ->cli('diet-filters', 'set')
            ->input(DietFiltersSetInput::class)
            ->run(function (DietFiltersSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: ($this->set)($user, $input->filters) ? [ReadKey::for('settings_show')] : []);
            });
    }
}

<?php

namespace App\Actions\Settings;

use App\Capabilities\Settings\Data\DietFilterInput;
use App\Domain\Model\DietFilter;
use App\Models\Profile;
use App\Models\User;
use DomainException;

/**
 * SET-006: the diet filters that are on. They add up: a food must match every one (FOOD-027). They never reach the
 * targets, so nothing is rebuilt, even while a failed rebuild is pending.
 */
final class SetDietFilters
{
    /**
     * @param  list<DietFilterInput>  $filters
     * @return bool whether the filters changed
     */
    public function __invoke(User $user, array $filters): bool
    {
        $on = array_map(fn (DietFilterInput $filter) => $filter->filter, $filters);
        // In the list's own order, each once.
        $chosen = array_values(array_map(
            fn (DietFilter $filter) => $filter->value,
            array_filter(DietFilter::cases(), fn (DietFilter $filter) => in_array($filter->value, $on, true)),
        ));

        $profile = Profile::query()->where('user_id', $user->id)->first() ?? throw new DomainException(SaveProfile::NO_PROFILE);
        $profile->fill(['diet_filters' => $chosen]);
        $changed = $profile->isDirty('diet_filters');
        $profile->save();

        return $changed;
    }
}

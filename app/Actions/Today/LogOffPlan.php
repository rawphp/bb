<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\OffPlanLogInput;
use App\Models\OffPlanEntry;
use App\Models\User;

/**
 * DAY-001: food eaten off-plan, an entry on the day with no meal slot. The client makes its id (CAP-019), so logging
 * the same id again edits the entry. A blank name is kept as none; the entry then shows as "Off-plan food".
 */
final class LogOffPlan
{
    public const DEFAULT_NAME = 'Off-plan food';

    public function __invoke(User $user, ?OffPlanEntry $existing, OffPlanLogInput $input): OffPlanEntry
    {
        $name = trim($input->name ?? '');
        $entry = $existing ?? new OffPlanEntry;
        $entry->forceFill([
            'id' => $input->id,
            'user_id' => $user->id,
            'date' => $input->date,
            'time' => $input->time,
            'name' => $name === '' ? null : $name,
            'kcal' => $input->kcal,
            'protein_g' => $input->protein_g,
            'fat_g' => $input->fat_g,
            'carbs_g' => $input->carbs_g,
        ])->save();

        return $entry;
    }
}

<?php

namespace App\Actions\Settings;

use App\Capabilities\Settings\Data\SettingsUpdateInput;
use App\Models\User;

/**
 * SET-001 to SET-003, EX-012: the units, appearance, consumed totals and micro plates. None of them reaches the
 * targets, so the save rebuilds only while a failed rebuild is pending ({@see SaveProfile}). Weights and heights are
 * stored in metric, so a unit change converts every screen's history as it is shown and rewrites none of it.
 */
final class UpdateSettings
{
    public function __construct(private readonly SaveProfile $save) {}

    /** @return list<string> the dates the rebuild wrote, Y-m-d */
    public function __invoke(User $user, SettingsUpdateInput $input): array
    {
        return ($this->save)($user, [
            'weight_unit' => $input->weight_unit,
            'height_unit' => $input->height_unit,
            'appearance' => $input->appearance,
            'consumed_totals' => $input->consumed_totals,
            'micro_plates' => $input->micro_plates,
        ]);
    }
}

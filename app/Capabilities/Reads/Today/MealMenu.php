<?php

namespace App\Capabilities\Reads\Today;

use App\Support\Today\TodayMealItem;

/**
 * MEAL-009, MEAL-045: the meal menu, one rule for Today's meal card and the meal screen (rp-clone TodayMealMenuEntry).
 * Actions that need foods are off on an empty meal, and check in is off while the meal has the Adjust foods badge.
 */
final class MealMenu
{
    /** MEAL-009, in order. */
    private const ITEMS = [
        'edit_macros_time' => 'Edit macros/time',
        'load_saved_meal' => 'Load saved meal',
        'save_as_saved_meal' => 'Save as saved meal',
        'copy_foods' => 'Copy foods',
        'remove_foods' => 'Remove foods',
        'check_in_meal' => 'Check in meal',
        'check_in_manual' => 'Check in at manual macros',
        'remove_check_in' => 'Remove check-in',
        'still_planning' => 'Still planning foods',
        'delete_meal' => 'Delete meal',
    ];

    /**
     * The ten items, each with whether it can be used now. A meal still being planned offers Done planning foods in
     * place of Still planning foods.
     *
     * @return list<array{item: string, title: string, enabled: bool}>
     */
    public static function items(TodayMealItem $meal): array
    {
        $badge = $meal->showsAdjustBadge();
        $items = [];
        foreach (self::ITEMS as $item => $title) {
            $enabled = match ($item) {
                'check_in_meal' => ! $meal->isChecked() && ! $badge,
                'remove_check_in' => $meal->isChecked(),
                'save_as_saved_meal', 'remove_foods' => $meal->foods !== [],
                'copy_foods' => $meal->hasEntries(),
                default => true,
            };
            if ($item === 'still_planning' && $meal->planningState === TodayMealItem::STILL_PLANNING) {
                [$item, $title] = ['done_planning', 'Done planning foods'];
            }
            $items[] = ['item' => $item, 'title' => $title, 'enabled' => $enabled];
        }

        return $items;
    }
}

<?php

namespace App\Capabilities\Today;

use App\Capabilities\Nutrition\MealReads;
use App\Models\DayTargetMeal;
use App\Models\User;

/**
 * The reads a copy changes (CAP-010, CAP-011): each written meal's screen and the Nutrition tab, Today and the week
 * strip of its day, through {@see MealReads}, and the Copy sheet itself.
 */
final class CopyReads
{
    public const AFFECTS = ['today_show', 'week_show', 'copy_show', 'meal_show', 'nutrition_show'];

    /**
     * @param  list<DayTargetMeal>  $written  the destination meals, with their day
     * @return list<string>
     */
    public static function keys(User $user, array $written): array
    {
        $user->loadMissing('plan');
        $keys = [];
        foreach ($written as $meal) {
            array_push($keys, ...MealReads::keys($meal->setRelation('user', $user)));
        }

        return array_values(array_unique($keys));
    }

    /** "Copied to 2 meals"; "Nothing was copied" when every destination was skipped. */
    public static function toast(int $count, string $noun): string
    {
        return $count === 0 ? 'Nothing was copied' : 'Copied to '.($count === 1 ? "1 {$noun}" : "{$count} {$noun}s");
    }
}

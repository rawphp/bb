<?php

namespace App\Domain\Placement;

/**
 * Places a day's meals in the eating window around its workout and busy periods (SCH-041 to SCH-045). Optimize
 * (SCH-036) is the same call with checked-in meals fixed.
 *
 * Rules: every meal the app places is inside the window and outside busy periods and the workout. Meals are at least
 * 2 hours apart, except the pre- and post-workout pair, and except two fixed meals, which keep whatever times they
 * were given. On a training day the pre-workout meal is 1 to 3 hours before the session, as near 90 minutes as it can
 * be, and the post-workout meal is within 60 minutes after it ends, as early as it can be. Either one is left out
 * when it cannot fit, as for a session trained before the first meal, and that meal is spaced as a regular one; null
 * means the window cannot hold every meal. A fixed meal in the 3 hours before the session is its pre-workout meal,
 * and one from its start to an hour after it ends is its post-workout meal. With only one free meal left the
 * post-workout meal gets it. The other meals are spread as evenly as the rules allow: the placement that maximises
 * the sum of the logs of the gaps between meals, which spaces them equally from the first to the last meal time when
 * nothing is in the way.
 */
final class MealPlacement
{
    public const MINIMUM_GAP_MINUTES = 120;

    public const PRE_WORKOUT_MINUTES_BEFORE_MIN = 60;

    public const PRE_WORKOUT_MINUTES_BEFORE_MAX = 180;

    public const PRE_WORKOUT_TARGET_MINUTES_BEFORE = 90;

    public const POST_WORKOUT_MINUTES_AFTER_MIN = 0;

    public const POST_WORKOUT_MINUTES_AFTER_MAX = 60;

    public const SHAKE_FOR_SESSIONS_OVER_MINUTES = 60;

    /**
     * App-placed meals land on these wall-clock marks, or on an exact edge (the window, a busy period's end, a
     * workout target, 2 hours from a fixed meal).
     */
    public const GRID_MINUTES = 5;

    /**
     * The day's placement, or null when the window, busy periods, workout and fixed meals leave no room for every
     * meal.
     *
     * @param  list<MealSlot>  $meals
     * @param  list<ClockRange>  $busy
     */
    public static function place(array $meals, ClockRange $window, array $busy = [], ?WorkoutTime $workout = null): ?DayPlacement
    {
        // Both peri-workout meals if they fit, else the post-workout one, else the pre-workout one, else neither; a
        // meal that is not peri-workout is spaced as a regular meal.
        $attempts = $workout === null
            ? [[]]
            : [[MealRole::PreWorkout, MealRole::PostWorkout], [MealRole::PostWorkout], [MealRole::PreWorkout], []];

        foreach ($attempts as $peri) {
            $solution = (new MealPlacementSolver($meals, $window, $busy, $workout, $peri))->solve();
            if ($solution !== null) {
                $shake = $workout !== null && $workout->lengthMinutes > self::SHAKE_FOR_SESSIONS_OVER_MINUTES
                    ? $workout->start
                    : null;

                return new DayPlacement($solution, $shake);
            }
        }

        return null;
    }
}

<?php

namespace App\Agent;

/**
 * Where a Coach change card opens (AGT-013): each write the agent can call, by
 * name, to the tab root its change shows on, or null when no screen shows it.
 * ChangeScreensTest fails when a write the tool catalog offers has no entry;
 * one missing at runtime opens nothing.
 */
final class ChangeScreens
{
    /** The tab roots (NAV-002, app/tabs.ts). */
    public const TABS = ['/today', '/train', '/nutrition', '/progress', '/more'];

    /** @var array<string, string|null> capability name => tab root */
    public const SCREENS = [
        'away_week_set' => '/today',
        'custom_exercise_save' => '/train',
        'custom_food_delete' => '/more',
        'custom_food_save' => '/more',
        'day_adjust' => '/today',
        'day_copy' => '/today',
        'day_optimize' => '/today',
        'eligibility_recheck' => '/more',
        'exercise_load_step_set' => '/train',
        'exercise_note_set' => '/train',
        'exercise_swap' => '/train',
        'food_favorite_set' => '/nutrition',
        'meal_add' => '/today',
        'meal_copy' => '/today',
        'meal_redistribute' => '/today',
        'meal_saved_meal_load' => '/today',
        'mesocycle_active_rest_take' => '/train',
        'mesocycle_build' => '/train',
        'mesocycle_copy' => '/train',
        'mesocycle_day_add' => '/train',
        'mesocycle_end' => '/train',
        'mesocycle_length_set' => '/train',
        'mesocycle_slots_fill' => '/train',
        'mesocycle_structure_generate' => '/train',
        'missed_session_resolve' => '/today',
        'missed_week_resolve' => '/today',
        'notification_prefs_set' => '/more',
        'off_plan_delete' => '/today',
        'off_plan_log' => '/today',
        'onboarding_answer' => null,
        'onboarding_complete' => '/today',
        'other_activity_delete' => '/today',
        'other_activity_save' => '/today',
        'phase_add' => '/train',
        'phase_end' => '/train',
        'phase_event_date_set' => '/train',
        'phase_goal_change' => '/train',
        'phase_length_set' => '/train',
        'phase_transition_complete' => '/train',
        'plan_diet_break_set' => '/train',
        'prebuilt_plan_apply' => '/train',
        'priority_set' => '/train',
        'push_subscribe' => '/more',
        'push_unsubscribe' => '/more',
        'recipe_delete' => '/nutrition',
        'recipe_save' => '/nutrition',
        'rough_week_set' => '/today',
        'saved_meal_delete' => '/more',
        'saved_meal_rename' => '/more',
        'saved_meal_save' => '/more',
        'shopping_item_add' => '/nutrition',
        'shopping_item_check' => '/nutrition',
        'shopping_item_delete' => '/nutrition',
        'steps_log' => '/progress',
        'undo_apply' => null,
        'week_copy' => '/today',
        'weigh_in_delete' => '/progress',
        'weigh_in_log' => '/progress',
        'weigh_in_schedule_set' => '/progress',
        'weigh_in_update' => '/progress',
        'workout_day_relabel' => '/train',
        'workout_exercise_add' => '/train',
        'workout_exercise_note_set' => '/train',
        'workout_exercise_remove' => '/train',
        'workout_exercise_reorder' => '/train',
        'workout_feedback_answer' => '/train',
        'workout_finish' => '/train',
        'workout_move' => '/today',
        'workout_set_add' => '/train',
        'workout_set_delete' => '/train',
        'workout_set_log' => '/train',
        'workout_set_skip' => '/train',
        'workout_set_type_set' => '/train',
        'workout_skip' => '/train',
        'workout_start' => '/train',
        'workout_time_set' => '/today',
    ];

    public static function for(string $capability): ?string
    {
        return self::SCREENS[$capability] ?? null;
    }
}

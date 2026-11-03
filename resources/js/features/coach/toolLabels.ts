// The line the chat shows when a Coach tool call comes back (AGT-021): capability name to
// what the Coach was doing, in words. Wording only; a name not here reads "Working…".
export const toolLabels: Readonly<Record<string, string>> = {
    today_show: 'Checking today…',
    week_show: 'Checking this week…',
    plan_show: 'Checking your plan…',
    train_show: 'Checking your training…',
    nutrition_show: 'Checking your nutrition…',
    progress_show: 'Checking your progress…',
    mesocycle_show: 'Checking your mesocycle…',
    workout_show: 'Checking your workout…',
    exercise_history_show: 'Checking your history…',
    exercises_show: 'Looking through exercises…',
    meal_show: 'Checking the meal…',
    food_search: 'Searching foods…',
    recipes_show: 'Looking through recipes…',
    shopping_show: 'Checking your shopping list…',
    weigh_ins_show: 'Checking your weigh-ins…',
    article_show: 'Reading the Learn article…',
    workout_set_log: 'Logging your set…',
    workout_start: 'Starting your workout…',
    workout_finish: 'Finishing your workout…',
    workout_move: 'Moving your workout…',
    workout_skip: 'Skipping the workout…',
    exercise_swap: 'Swapping the exercise…',
    meal_add: 'Adding a meal…',
    meal_food_add: 'Adding the food…',
    meal_check_in: 'Checking in your meal…',
    meal_rebalance: 'Rebalancing your meals…',
    day_adjust: 'Adjusting your day…',
    weigh_in_log: 'Logging your weight…',
    steps_log: 'Logging your steps…',
    shopping_item_add: 'Adding to your shopping list…',
    undo_apply: 'Undoing that…',
    onboarding_show: 'Checking your setup…',
    onboarding_answer: 'Saving your answers…',
    onboarding_complete: 'Starting your plan…',
};

export function toolLine(name: string): string {
    return toolLabels[name] ?? 'Working…';
}

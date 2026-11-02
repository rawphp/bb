<?php

namespace App\Domain\Learn;

/** A feature screen with a ? icon, and the article it opens (LRN-003). A new feature screen adds its case here. */
enum LearnScreen: string
{
    case Today = 'today';
    case Train = 'train';
    case Nutrition = 'nutrition';
    case Progress = 'progress';
    case Onboarding = 'onboarding';
    case Workout = 'workout';

    public function topic(): LearnTopic
    {
        return match ($this) {
            self::Today => LearnTopic::DayBalance,
            self::Train => LearnTopic::Mesocycles,
            self::Nutrition => LearnTopic::BuildingMeals,
            self::Progress => LearnTopic::WeighingIn,
            self::Onboarding => LearnTopic::ToolsYouNeed,
            self::Workout => LearnTopic::RestTimes,
        };
    }
}

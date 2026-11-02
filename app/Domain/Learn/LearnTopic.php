<?php

namespace App\Domain\Learn;

use App\Domain\Model\PhaseType;

/**
 * One Learn article (LRN-001, LRN-002, LRN-005). The value is its slug and
 * names its Markdown file, resources/learn/<value>.md. The order is the index's.
 */
enum LearnTopic: string
{
    // Using the app
    case NotATracker = 'notATracker';
    case IsItRightForMe = 'isItRightForMe';
    case ToolsYouNeed = 'toolsYouNeed';
    case ChoosingAGoal = 'choosingAGoal';
    case CheckIns = 'checkIns';
    case DayBalance = 'dayBalance';
    // Diet
    case FatLoss = 'fatLoss';
    case MiniCut = 'miniCut';
    case Maintenance = 'maintenance';
    case MuscleGain = 'muscleGain';
    case BodyFat = 'bodyFat';
    case WeighingIn = 'weighingIn';
    case WhyWeightGoesUp = 'whyWeightGoesUp';
    case MealsPerDay = 'mealsPerDay';
    case BuildingMeals = 'buildingMeals';
    case Alcohol = 'alcohol';
    case Coffee = 'coffee';
    case Oils = 'oils';
    case EatingOut = 'eatingOut';
    // Training
    case Mesocycles = 'mesocycles';
    case Rir = 'rir';
    case RepRanges = 'repRanges';
    case WarmUps = 'warmUps';
    case RestTimes = 'restTimes';
    case FeedbackQuestions = 'feedbackQuestions';
    case Deloads = 'deloads';
    case NotGettingPumps = 'notGettingPumps';
    case LongWorkouts = 'longWorkouts';
    case MissingADay = 'missingADay';
    case TrainingWhileSick = 'trainingWhileSick';
    case TrainingAlongsideSport = 'trainingAlongsideSport';
    case HomeGyms = 'homeGyms';
    // Training and diet together
    case TrainingOnACut = 'trainingOnACut';
    case DeloadsAtMaintenance = 'deloadsAtMaintenance';
    case MaintenanceAndResensitization = 'maintenanceAndResensitization';
    case CarbsAroundWorkouts = 'carbsAroundWorkouts';
    case LiftsStallInADiet = 'liftsStallInADiet';

    /** The article for a phase type ("each phase type", LRN-001). */
    public static function forPhase(PhaseType $type): self
    {
        return match ($type) {
            PhaseType::FatLoss => self::FatLoss,
            PhaseType::MiniCut => self::MiniCut,
            PhaseType::Maintenance => self::Maintenance,
            PhaseType::MuscleGain => self::MuscleGain,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::NotATracker => 'How this app differs from a tracker',
            self::IsItRightForMe => 'Is this app right for me?',
            self::ToolsYouNeed => 'Tools you need',
            self::ChoosingAGoal => 'Choosing a goal',
            self::CheckIns => 'Check-ins',
            self::DayBalance => 'Day Balance',
            self::FatLoss => 'Fat loss phases',
            self::MiniCut => 'Mini-cuts',
            self::Maintenance => 'Maintenance phases',
            self::MuscleGain => 'Muscle gain phases',
            self::BodyFat => 'Estimating body fat',
            self::WeighingIn => 'Weighing in',
            self::WhyWeightGoesUp => 'Why your weight went up',
            self::MealsPerDay => 'Meals per day',
            self::BuildingMeals => 'Building meals',
            self::Alcohol => 'Alcohol',
            self::Coffee => 'Coffee and caffeine',
            self::Oils => 'Cooking oils',
            self::EatingOut => 'Eating out',
            self::Mesocycles => 'Mesocycles',
            self::Rir => 'Reps in reserve (RIR)',
            self::RepRanges => 'Rep ranges',
            self::WarmUps => 'Warm-ups',
            self::RestTimes => 'Rest times',
            self::FeedbackQuestions => 'Answering the feedback questions',
            self::Deloads => 'Deloads',
            self::NotGettingPumps => 'Not getting a pump',
            self::LongWorkouts => 'Long workouts',
            self::MissingADay => 'Missing a day',
            self::TrainingWhileSick => 'Training while sick',
            self::TrainingAlongsideSport => 'Training alongside a sport',
            self::HomeGyms => 'Home gyms',
            self::TrainingOnACut => 'Training on a cut',
            self::DeloadsAtMaintenance => 'Deloads at maintenance',
            self::MaintenanceAndResensitization => 'Maintenance and resensitization',
            self::CarbsAroundWorkouts => 'Why the app moves your carbs around workouts',
            self::LiftsStallInADiet => 'When lifts stall in a diet',
        };
    }

    public function section(): LearnSection
    {
        return match ($this) {
            self::NotATracker, self::IsItRightForMe, self::ToolsYouNeed, self::ChoosingAGoal, self::CheckIns, self::DayBalance => LearnSection::UsingTheApp,
            self::FatLoss, self::MiniCut, self::Maintenance, self::MuscleGain, self::BodyFat, self::WeighingIn, self::WhyWeightGoesUp,
            self::MealsPerDay, self::BuildingMeals, self::Alcohol, self::Coffee, self::Oils, self::EatingOut => LearnSection::Diet,
            self::Mesocycles, self::Rir, self::RepRanges, self::WarmUps, self::RestTimes, self::FeedbackQuestions, self::Deloads,
            self::NotGettingPumps, self::LongWorkouts, self::MissingADay, self::TrainingWhileSick, self::TrainingAlongsideSport, self::HomeGyms => LearnSection::Training,
            self::TrainingOnACut, self::DeloadsAtMaintenance, self::MaintenanceAndResensitization, self::CarbsAroundWorkouts,
            self::LiftsStallInADiet => LearnSection::Together,
        };
    }
}

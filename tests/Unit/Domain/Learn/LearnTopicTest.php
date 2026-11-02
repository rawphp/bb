<?php

use App\Domain\Learn\LearnScreen;
use App\Domain\Learn\LearnSection;
use App\Domain\Learn\LearnTopic;
use App\Domain\Model\PhaseType;

// The Learn topics, their index and the screens that link to them, ported from rp-clone's LearnTopic.

it('LRN-001 holds an article for every topic the spec lists', function () {
    $expected = [
        'notATracker', 'isItRightForMe', 'choosingAGoal',
        'fatLoss', 'miniCut', 'maintenance', 'muscleGain',
        'bodyFat', 'weighingIn', 'whyWeightGoesUp', 'mealsPerDay', 'buildingMeals',
        'checkIns', 'dayBalance', 'alcohol', 'coffee', 'oils', 'eatingOut',
        'mesocycles', 'rir', 'repRanges', 'warmUps', 'restTimes', 'feedbackQuestions',
        'deloads', 'notGettingPumps', 'longWorkouts', 'missingADay',
        'trainingWhileSick', 'trainingAlongsideSport', 'homeGyms',
    ];

    foreach ($expected as $slug) {
        expect(LearnTopic::tryFrom($slug))->not->toBeNull("{$slug} has no topic");
    }
});

it('LRN-001 has an article for each phase type', function () {
    expect(array_map(fn (PhaseType $type) => LearnTopic::forPhase($type), PhaseType::cases()))
        ->toBe([LearnTopic::FatLoss, LearnTopic::MiniCut, LearnTopic::Maintenance, LearnTopic::MuscleGain]);
});

it('LRN-002 groups the training and diet articles in their own section', function () {
    expect(LearnSection::Together->topics())->toBe([
        LearnTopic::TrainingOnACut, LearnTopic::DeloadsAtMaintenance, LearnTopic::MaintenanceAndResensitization,
        LearnTopic::CarbsAroundWorkouts, LearnTopic::LiftsStallInADiet,
    ])->and(LearnSection::Together->title())->toBe('Training and diet together');
});

it('LRN-001 lists every topic once in the index, each with its own title', function () {
    $listed = array_merge(...array_map(fn (LearnSection $section) => $section->topics(), LearnSection::cases()));
    $titles = array_map(fn (LearnTopic $topic) => $topic->title(), LearnTopic::cases());

    expect($listed)->toHaveCount(count(LearnTopic::cases()))
        ->and(array_unique(array_map(fn (LearnTopic $topic) => $topic->value, $listed)))->toHaveCount(count(LearnTopic::cases()))
        ->and(array_unique($titles))->toHaveCount(count(LearnTopic::cases()))
        ->and(array_map(fn (LearnSection $section) => $section->title(), LearnSection::cases()))
        ->toBe(['Using the app', 'Diet', 'Training', 'Training and diet together']);
});

it('LRN-003 maps each feature screen\'s ? icon to its matching article', function () {
    expect(array_map(fn (LearnScreen $screen) => [$screen->value, $screen->topic()], LearnScreen::cases()))->toBe([
        ['today', LearnTopic::DayBalance],
        ['train', LearnTopic::Mesocycles],
        ['nutrition', LearnTopic::BuildingMeals],
        ['progress', LearnTopic::WeighingIn],
        ['onboarding', LearnTopic::ToolsYouNeed],
        ['workout', LearnTopic::RestTimes],
    ]);
});

it('LRN-005 lists the tools article under Using the app', function () {
    expect(LearnSection::UsingTheApp->topics())->toContain(LearnTopic::ToolsYouNeed)
        ->and(LearnTopic::ToolsYouNeed->section())->toBe(LearnSection::UsingTheApp);
});

<?php

use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\TargetRefusal;
use App\Domain\Review\DropFatigueReason;
use App\Domain\Review\FatigueAdvice;
use App\Domain\Review\RecommendationReason;
use App\Domain\Review\StallChange;
use App\Domain\Review\StallOptions;
use App\Domain\Review\StepPlanOffer;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Support\Review\ReviewMessages;

// rp-clone ReviewMessages: the words the review gives the rules' answers. No database.

it('REV-043 REV-041 REV-042 the fatigue advice in words', function () {
    expect(ReviewMessages::fatigue(FatigueAdvice::endFatLoss(), 0))
        ->toBe('Your training fatigue stayed high after a diet break and a deload. Consider ending fat loss and moving to Maintenance.')
        ->and(ReviewMessages::fatigue(FatigueAdvice::dropFatigue(DropFatigueReason::WeeksSinceBreak), 7))
        ->toBe('You have been in a deficit for 7 weeks without a diet break or deload.')
        ->and(ReviewMessages::fatigue(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue), 2))
        ->toBe('Your lifts are dropping. It might be the diet, not the training.');
});

it('REV-023 REV-024 REV-026 REV-027 REV-028 REV-029 REV-032 each reason in words', function () {
    $reason = fn (RecommendationReason $reason) => ReviewMessages::reason($reason, 2400);

    expect($reason(RecommendationReason::stalled(new StallOptions(250, 2000, StallChange::CutCalories))))->toBe('Your progress has stalled')
        ->and($reason(RecommendationReason::waterWeight()))->toBe('Water weight is up. Holding your target this week.')
        ->and($reason(RecommendationReason::towardTargetRate()))->toBe('Your rate is off target, so calories move toward it.')
        ->and($reason(RecommendationReason::changeTooSmall()))->toBe('The change would be under 50 kcal, so calories stay as they are.')
        ->and($reason(RecommendationReason::onTarget()))->toBe('You are on target, so calories stay as they are.')
        ->and($reason(RecommendationReason::insideCorridor()))->toBe('Your trend weight is inside its corridor, so calories stay as they are.')
        ->and($reason(RecommendationReason::outsideCorridor()))->toBe('Your trend weight is outside its corridor, so calories steer it back.')
        ->and($reason(RecommendationReason::gainStalled()))->toBe('Your gain has stalled, so calories go up.')
        ->and($reason(RecommendationReason::gainTooFast()))->toBe('You are gaining faster than planned, so calories come down.')
        ->and($reason(RecommendationReason::returnFromBreak()))->toBe('After a break, calories go straight back to your last ordinary target.')
        ->and($reason(RecommendationReason::firstWeekOfPhase()))->toBe('The first week of a phase is left out of the rate, so calories hold.')
        ->and($reason(RecommendationReason::noTrend()))->toBe('There is not enough trend weight yet to read this week, so calories hold.');
});

it('REV-025 poor adherence says what was logged or eaten against the target', function () {
    expect(ReviewMessages::reason(RecommendationReason::poorAdherence(4, 2300.4), 2400))
        ->toBe('You logged 4 of 7 days. Hit your targets first, then the app adjusts.')
        ->and(ReviewMessages::reason(RecommendationReason::poorAdherence(0, null), 2400))
        ->toBe('You logged 0 of 7 days. Hit your targets first, then the app adjusts.')
        ->and(ReviewMessages::reason(RecommendationReason::poorAdherence(6, 1919.6), 2400.4))
        ->toBe('You averaged 1,920 kcal against a target of 2,400 kcal. Hit your targets first, then the app adjusts.');
});

it('REV-030 SAFE-011 SAFE-012 the safety reasons in words', function () {
    expect(ReviewMessages::reason(RecommendationReason::safety(TargetRefusal::bmiAtOrBelowFloor(16.5)), 2400))
        ->toBe('Your BMI is too low to plan a cut, so calories rise to maintenance.')
        ->and(ReviewMessages::reason(RecommendationReason::safety(TargetRefusal::breaksFloor(2)), 2400))
        ->toBe('A cut would take your fat or carbs below the safe minimum, so calories hold.');
});

it('REV-046 SCH-064 the new reasons in words', function () {
    expect(ReviewMessages::reason(RecommendationReason::losingTooFast(), 2400))
        ->toBe('You\'re losing faster than planned and your lifts are paying for it.')
        ->and(ReviewMessages::reason(RecommendationReason::roughWeek(), 2400))
        ->toBe('Rough week. Holding training and calories.');
});

it('REV-050 the step offer in words', function () {
    expect(ReviewMessages::steps(new StepPlanOffer(10000, 7000)))
        ->toBe('You walked 10,000 steps a day on average against a plan of 7,000. Update your step plan?')
        ->and(ReviewMessages::steps(new StepPlanOffer(12345.6, 9999.5)))
        ->toBe('You walked 12,346 steps a day on average against a plan of 10,000. Update your step plan?');
});

it('SAFE-011 SAFE-012 the ways next week can be refused, in words', function () {
    expect(ReviewMessages::refusal(WeekBuildRefusal::noPhase()))
        ->toBe('No phase covers next week, so its targets can\'t be planned.')
        ->and(ReviewMessages::refusal(WeekBuildRefusal::targets(TargetRefusal::bmiAtOrBelowFloor(16.5))))
        ->toBe('Your BMI is too low for calories below maintenance. Choose maintenance or more.')
        ->and(ReviewMessages::refusal(WeekBuildRefusal::targets(TargetRefusal::breaksFloor(1))))
        ->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and(ReviewMessages::refusal(WeekBuildRefusal::mealsDoNotFit(3)))
        ->toBe('Your eating window can\'t hold your meals around your busy periods and workouts.')
        ->and(ReviewMessages::refusal(WeekBuildRefusal::move(MoveRefusal::dayCompleted(0))))
        ->toBe('A day stays between 80% and 125% of the week\'s average, and a day already past can\'t change.');
});

it('reads numbers with en_US grouping, rounded', function () {
    expect(ReviewMessages::kcal(2400))->toBe('2,400')
        ->and(ReviewMessages::kcal(1919.5))->toBe('1,920')
        ->and(ReviewMessages::kcal(999.4))->toBe('999')
        ->and(ReviewMessages::kcal(-1250.5))->toBe('-1,251')
        ->and(ReviewMessages::number(12000))->toBe('12,000');
});

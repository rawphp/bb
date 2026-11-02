<?php

use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Workout\FeedbackQuestions;
use App\Domain\Workout\WorkoutHelp;

// The wording of the Feedback sheet (FB-002 to FB-006, SAFE-016) and the deload help (PROG-043), from rp-clone's
// FeedbackSheetModel and WorkoutHelp.

it('FB-002 asks about the joints of each exercise by name', function () {
    expect(FeedbackQuestions::joint('Bench Press'))->toBe('How did your joints feel during Bench Press?')
        ->and(array_values(FeedbackQuestions::JOINT_PAIN))->toBe(['None', 'Low pain', 'Moderate pain', 'A lot of pain'])
        ->and(array_keys(FeedbackQuestions::JOINT_PAIN))->toBe(array_map(fn (JointPain $pain) => $pain->value, JointPain::cases()));
});

it('FB-003 to FB-005 the muscle questions and their answers use the spec wording', function () {
    expect(FeedbackQuestions::soreness(MuscleGroup::SideDelts))->toBe('How sore did you get in your side delts AFTER training them LAST TIME?')
        ->and(FeedbackQuestions::pump(MuscleGroup::Chest))->toBe('How much of a pump did you get today in your chest?')
        ->and(FeedbackQuestions::volume(MuscleGroup::Chest))->toBe('How was the volume of work (number of hard sets) for your chest?')
        ->and(FeedbackQuestions::SORENESS)->toBe([
            Soreness::NeverGotSore->value => 'Never got sore', Soreness::HealedAWhileAgo->value => 'Healed a while ago',
            Soreness::HealedJustOnTime->value => 'Healed just on time', Soreness::StillSore->value => 'I\'m still sore!',
        ])
        ->and(FeedbackQuestions::PUMP)->toBe([Pump::Low->value => 'Low pump', Pump::Moderate->value => 'Moderate pump', Pump::Amazing->value => 'Amazing pump'])
        ->and(FeedbackQuestions::VOLUME)->toBe([
            VolumeFeedback::NotEnough->value => 'Not enough', VolumeFeedback::JustRight->value => 'Just right',
            VolumeFeedback::PushedMyLimits->value => 'Pushed my limits', VolumeFeedback::TooMuch->value => 'Too much',
        ]);
});

it('FB-006 every question has help that says how its answers change next week, with the article', function () {
    foreach (['joint', 'soreness', 'pump', 'volume'] as $kind) {
        $help = FeedbackQuestions::help($kind);

        expect($help['article'])->toBe('feedback-questions')
            ->and($help['body'])->toContain('next week');
    }
    expect(FeedbackQuestions::help('volume')['title'])->toBe('Volume');
});

it('SAFE-016 a lot of pain carries the safety note', function () {
    expect(FeedbackQuestions::PAIN_NOTE)->toBe('Stop this exercise if the pain is sharp, and see a professional if it persists.');
});

it('PROG-043 the deload help says it should feel easy, after pushing the 0 RIR week', function () {
    expect(WorkoutHelp::DELOAD)->toBe('The deload is meant to feel easy. Push the 0 RIR week as hard as possible before it.')
        ->and(WorkoutHelp::FEEDBACK_REQUIRED)->toBe('Your feedback is required');
});

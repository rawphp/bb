<?php

namespace App\Capabilities\Reads\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingShowOutput;
use App\Capabilities\Onboarding\Data\OnboardingStep;
use App\Capabilities\Onboarding\Data\OnboardingStepMark;
use App\Capabilities\Support\Status;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\Onboarding\OnboardingHealth;
use App\Domain\Onboarding\OnboardingLifestyle;
use App\Domain\Onboarding\OnboardingRateLength;
use App\Domain\Onboarding\OnboardingReview;
use App\Domain\Onboarding\OnboardingSteps;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\OnboardingTraining;
use App\Domain\Onboarding\StepMark;
use App\Models\OnboardingDraft;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * Setup as saved on the server (ONB-002), every step's answers and the review. Every value is worked out by
 * `App\Domain\Onboarding`; this class loads the draft and the templates that fit it and lays the values out. A user
 * with no saved draft reads the defaults, and reading never writes.
 */
final class OnboardingShowPayload
{
    public static function for(User $user): OnboardingShowOutput
    {
        $draft = OnboardingDraft::for($user)->toDraft();

        return self::build($draft, TrainSnapshot::day(Carbon::now()), OnboardingDraft::templates($draft));
    }

    /** @param  list<OnboardingTemplate>  $templates  the library, as step 9 reads it */
    public static function build(Draft $draft, DateTimeImmutable $today, array $templates): OnboardingShowOutput
    {
        // One calorie state for the whole read: step 13, the step check and the review all show the same week.
        $calories = new OnboardingCalories($draft, $today, $templates);
        $steps = new OnboardingSteps($draft, $today, $templates, $calories);
        $health = new OnboardingHealth($draft, $today);
        $rateLength = new OnboardingRateLength($draft, $today);
        $training = new OnboardingTraining($draft, $templates);
        $lifestyle = new OnboardingLifestyle($draft);

        return new OnboardingShowOutput(
            step: self::step($draft, $steps),
            training_only: $health->isTrainingOnly(),
            notices: self::notices($draft, $health, $rateLength, $lifestyle),
            goal: OnboardingHealthPayload::goal($draft, $health),
            about_you: OnboardingHealthPayload::aboutYou($draft, $health, $today),
            screening: OnboardingHealthPayload::screening($health),
            glp1: OnboardingHealthPayload::glp1($draft),
            body_fat: OnboardingHealthPayload::bodyFat($draft, $health),
            rate_length: OnboardingRateLengthPayload::build($draft, $rateLength),
            experience: OnboardingTrainingPayload::experience($draft),
            training_days: OnboardingTrainingPayload::trainingDays($draft),
            training_plan: OnboardingTrainingPayload::trainingPlan($draft, $training),
            steps: OnboardingLifestylePayload::steps($lifestyle),
            meals: OnboardingLifestylePayload::meals($draft),
            weigh_ins: OnboardingLifestylePayload::weighIns($draft, $lifestyle),
            calories: OnboardingLifestylePayload::calories($draft, $calories),
            review: OnboardingReviewPayload::build(new OnboardingReview($draft, $today, $templates, $calories)),
        );
    }

    private static function step(Draft $draft, OnboardingSteps $steps): OnboardingStep
    {
        $position = $steps->position();

        return new OnboardingStep(
            number: $draft->step, key: $steps->key(), title: $steps->title(), counter: $position->counter(),
            position: $position->current, total: $position->total, next_title: $steps->nextTitle(),
            can_continue: $steps->canContinue(), continue_hint: $steps->continueHint(), can_go_back: $steps->canGoBack(),
            track: array_map(
                fn (StepMark $mark) => new OnboardingStepMark(step: $mark->step, label: $mark->label, state: $mark->state, can_open: $mark->canOpen),
                $steps->track(),
            ),
        );
    }

    /**
     * The callouts for the current step, in order: Fat loss turned off (step 1), nutrition targets turned off
     * (training-only, after step 3), the fat-loss cautions (step 6) and the step-target caution (step 10).
     *
     * @return list<Status>
     */
    private static function notices(Draft $draft, OnboardingHealth $health, OnboardingRateLength $rateLength, OnboardingLifestyle $lifestyle): array
    {
        $notices = [];
        if ($draft->step === 1 && $health->notice() !== null) {
            $notices[] = OnboardingParts::status($health->notice(), 'warning');
        }
        if ($health->trainingOnlyNotice() !== null) {
            $notices[] = OnboardingParts::status($health->trainingOnlyNotice(), 'info');
        }
        if ($draft->step === 6) {
            foreach ($rateLength->warnings() as $warning) {
                $notices[] = OnboardingParts::status($warning, 'warning');
            }
        }
        if ($draft->step === 10 && $lifestyle->stepWarning() !== null) {
            $notices[] = OnboardingParts::status($lifestyle->stepWarning(), 'warning');
        }

        return $notices;
    }
}

<?php

namespace App\Actions\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingAnswerInput;
use App\Capabilities\Support\FieldRefused;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingDates;
use App\Domain\Onboarding\OnboardingStart;
use App\Domain\Onboarding\OnboardingSteps;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Models\OnboardingDraft;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * onboarding_answer (ONB-002 to ONB-018): saves setup answers and moves between the steps, in one transaction. Each
 * answer goes through the rule of its rp-clone setter ({@see DraftAnswers}); the move's target, and whether the step
 * can continue, come from {@see OnboardingSteps}. A refused Next or Edit saves nothing.
 */
final class AnswerOnboarding
{
    public const NO_EDIT_STEP = 'Say which step to edit.';

    public const STEP_NOT_SHOWN = 'That step is not part of your setup.';

    public function __invoke(User $user, OnboardingAnswerInput $input): void
    {
        DB::transaction(function () use ($user, $input): void {
            // The user is locked first, so two answers at once are applied one after the other, the first one too.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $row = OnboardingDraft::query()->where('user_id', $user->id)->lockForUpdate()->first() ?? OnboardingDraft::for($user);
            $draft = self::answer(
                $row->toDraft(),
                $input,
                TrainSnapshot::day(Carbon::now()),
                OnboardingDraft::templates(...),
                fn (): string => (string) Str::uuid7(),
            );
            $row->fillFrom($draft)->save();
        });
    }

    /**
     * The draft with the answers applied and the move made. Nothing here reads the database or the clock.
     *
     * @param  Closure(Draft): list<OnboardingTemplate>  $templates  the library for a draft, as step 9 reads it
     * @param  Closure(): string  $newId  an id for a new busy period
     *
     * @throws FieldRefused when an answer is not one the step offers, or the move cannot be made (422)
     */
    public static function answer(Draft $draft, OnboardingAnswerInput $input, DateTimeImmutable $today, Closure $templates, Closure $newId): Draft
    {
        $draft = DraftAnswers::apply($draft, $input->answers, $today, $templates, $newId);
        if ($input->move === 'stay') {
            return $draft;
        }
        $steps = new OnboardingSteps($draft, $today, $templates($draft));

        return match ($input->move) {
            'next' => self::next($draft, $steps, $today),
            'back' => self::moveTo($draft, $steps->back(), $today),
            default => self::edit($draft, $steps, $input->edit_step),
        };
    }

    /** ONB-005: the screening may send a Fat loss goal back to step 1 with the goal cleared. */
    private static function next(Draft $draft, OnboardingSteps $steps, DateTimeImmutable $today): Draft
    {
        if (! $steps->canContinue()) {
            throw new FieldRefused('move', $steps->continueHint() ?? OnboardingStart::INCOMPLETE_MESSAGE);
        }
        $move = $steps->next();
        if ($move === null) {
            return $draft;
        }

        return self::moveTo($move->clearsGoal ? $draft->with(goal: null) : $draft, $move->step, $today);
    }

    /** ONB-018: from the review back to a step that is shown. Every answer is kept. */
    private static function edit(Draft $draft, OnboardingSteps $steps, ?int $step): Draft
    {
        if ($step === null) {
            throw new FieldRefused('edit_step', self::NO_EDIT_STEP);
        }
        $target = $steps->edit($step);
        if ($target === null) {
            throw new FieldRefused('edit_step', self::STEP_NOT_SHOWN);
        }

        return $draft->with(step: $target);
    }

    /**
     * ONB-020: the plan starts tomorrow at the earliest, so entering the review moves a start in the past to tomorrow
     * and keeps the length (rp-clone's moveStartToTomorrowIfPast).
     */
    private static function moveTo(Draft $draft, ?int $step, DateTimeImmutable $today): Draft
    {
        if ($step === null) {
            return $draft;
        }
        $draft = $draft->with(step: $step);
        $tomorrow = OnboardingDates::tomorrow($today);
        if ($step === OnboardingSteps::REVIEW_STEP && $draft->startDate !== null && $draft->startDate < $tomorrow) {
            return $draft->with(startDate: $tomorrow);
        }

        return $draft;
    }
}

<?php

namespace App\Capabilities\Review;

use App\Actions\Review\CompleteReview;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Plan\PlanReads;
use App\Capabilities\Review\Data\ReviewCompleteInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\TodayReads;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ReviewCompleteCapability implements HostCapability
{
    public function __construct(private readonly CompleteReview $complete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // Next week's days change Today and its sheets; a diet break or a moved deload changes the plan, its phases and
        // the mesocycle (PLAN-025, REV-011); the stamp closes the review on Progress.
        return HostDefinition::write('review_complete', affects: ['review_show', 'progress_show', ...TodayReads::SHEETS, ...PlanReads::AFFECTS])
            ->description('Save the weekly review open for week_start as answered with review_answer, or as review_show first shows it when nothing was answered: next week\'s plan week and day targets, a diet break and moved deload for Drop fatigue, and any step plan change, then the week is marked reviewed. Use it once the user confirms the preview. Calorie moves that no longer fit, such as one from a day that has passed, are left out, as review_show shows; it answers 422 when the review is not open or its answers do not build.')
            ->cli('review', 'complete')
            ->input(ReviewCompleteInput::class)
            ->run(function (ReviewCompleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $saved = ($this->complete)($user, $input->week_start);

                return new WriteResult(
                    invalidates: array_values(array_unique([
                        ReadKey::for('review_show'),
                        ReadKey::for('progress_show'),
                        ReadKey::for('progress_show', ['scope' => 'phase']),
                        ReadKey::for('progress_show', ['scope' => 'plan']),
                        ReadKey::for('today_show'),
                        ...TodayReads::forDays($saved['dates'], $saved['dates']),
                        ...PlanReads::everyPhaseKeys($user),
                        ...($saved['mesocycle'] === null ? [] : MesocycleReads::keys($saved['mesocycle'])),
                    ])),
                    toast: 'Review saved',
                );
            });
    }
}

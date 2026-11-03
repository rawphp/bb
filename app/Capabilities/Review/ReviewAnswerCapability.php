<?php

namespace App\Capabilities\Review;

use App\Actions\Review\AnswerReview;
use App\Capabilities\HostCapability;
use App\Capabilities\Review\Data\ReviewAnswerInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ReviewAnswerCapability implements HostCapability
{
    public function __construct(private readonly AnswerReview $answer) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('review_answer', affects: ['review_show'])
            ->description('Store the weekly review\'s answers so far for the open review\'s week_start, sent whole each time: the calorie option review_show lists, its own amount, the deload and step plan answers, and the calorie moves between next week\'s days. A move that would break the week\'s limits is kept out and review_show says why. It applies nothing; review_complete saves the review.')
            ->cli('review', 'answer')
            ->input(ReviewAnswerInput::class)
            ->run(function (ReviewAnswerInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->answer)($user, $input);

                return new WriteResult(invalidates: [ReadKey::for('review_show')]);
            });
    }
}

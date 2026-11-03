<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Review\ReviewShowPayload;
use App\Capabilities\Reads\Review\ReviewUnavailable;
use App\Capabilities\Review\Data\ReviewShowInput;
use App\Capabilities\Review\Data\ReviewShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewData;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ReviewShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('review_show')
            ->description('Show the weekly review of the week that just ended: the weigh-ins, meals, training, progress and goal steps, then the calorie options with the reason for the recommendation and next week\'s calories and macros for each, and a preview of next week\'s days; a step the review\'s mode leaves out is null, and when there is nothing to review it says why. Use it to see what the review would set for next week before it is saved. It changes nothing.')
            ->cli('review', 'show')
            ->input(ReviewShowInput::class)
            ->output(ReviewShowOutput::class)
            ->run(function (ReviewShowInput $input, CapabilityContext $context): ReviewShowOutput {
                /** @var User $user */
                $user = $context->user();
                $data = ReviewData::load($user, CarbonImmutable::now());
                $analysis = ReviewAnalysis::fromData($data);

                return $analysis === null
                    ? ReviewShowPayload::unavailable(ReviewUnavailable::message($data))
                    : ReviewShowPayload::build($analysis, ReviewAnswer::answersFor($user, $analysis->period->reviewed->start->format('Y-m-d')));
            });
    }
}

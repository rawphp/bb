<?php

namespace App\Capabilities\Onboarding;

use App\Actions\Onboarding\AnswerOnboarding;
use App\Capabilities\HostCapability;
use App\Capabilities\Onboarding\Data\OnboardingAnswerInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OnboardingAnswerCapability implements HostCapability
{
    public function __construct(private readonly AnswerOnboarding $answer) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('onboarding_answer', affects: ['onboarding_show'])
            ->description('Save setup answers from steps 1 to 13 (goal, about you, health screening, weight-loss drug, body fat, rate and length, experience, training days and plan, steps, meals, weigh-ins, calories), then stay on the step, go to the next or the previous one, or go back from the review to edit a step; a new goal puts the rate and length back to that goal\'s defaults, and a new end date or target weight works out the length again. Use it to answer a few questions at a time with move "stay", or when the user presses Next, Back or Edit. It refuses, from the Coach or MCP, a health check answer, Next on the health check and Edit from anywhere but the review, as the user answers that check on its own sheet; it also refuses Next while the step cannot continue, with that step\'s continue hint, and a choice the step does not offer, and then saves nothing.')
            ->cli('onboarding', 'answer')
            ->input(OnboardingAnswerInput::class)
            ->run(function (OnboardingAnswerInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->answer)($user, $input, byChat: in_array($context->caller(), ['agent', 'mcp'], true));

                return new WriteResult(invalidates: [ReadKey::for('onboarding_show')]);
            });
    }
}

<?php

namespace App\Capabilities\Onboarding;

use App\Capabilities\HostCapability;
use App\Capabilities\Onboarding\Data\OnboardingShowInput;
use App\Capabilities\Onboarding\Data\OnboardingShowOutput;
use App\Capabilities\Reads\Onboarding\OnboardingShowPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OnboardingShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('onboarding_show')
            ->description('Show setup as saved: the current step with its counter and what Next needs, every step\'s answers and choices (goal, about you, health screening, weight-loss drug, body fat, rate and length, experience, training days and plan, steps, meals, weigh-ins, calories), the notices for the current step and the review of the plan. Use it to see where the user is in setup and what is left. It changes nothing.')
            ->cli('onboarding', 'show')
            ->input(OnboardingShowInput::class)
            ->output(OnboardingShowOutput::class)
            ->run(function (OnboardingShowInput $input, CapabilityContext $context): OnboardingShowOutput {
                /** @var User $user */
                $user = $context->user();

                return OnboardingShowPayload::for($user);
            });
    }
}

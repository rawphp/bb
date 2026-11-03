<?php

namespace App\Capabilities\Reads;

use App\Actions\Settings\RecheckEligibility;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\EligibilityRecheckShowInput;
use App\Capabilities\Reads\Data\EligibilityRecheckShowOutput;
use App\Capabilities\Reads\Settings\EligibilityRecheckPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\Profile;
use App\Models\User;
use App\Support\UserDay;
use DomainException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class EligibilityRecheckShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('eligibility_recheck_show')
            ->description('Show the health screening again for Settings → Re-check eligibility: every screening item, the ones the user ticked last time, the ones measured from their profile (which cannot be ticked), and whether they are on training and nutrition or training only, and why. Use it before eligibility_recheck. It changes nothing.')
            ->cli('eligibility', 'show')
            ->input(EligibilityRecheckShowInput::class)
            ->output(EligibilityRecheckShowOutput::class)
            ->run(function (EligibilityRecheckShowInput $input, CapabilityContext $context): EligibilityRecheckShowOutput {
                /** @var User $user */
                $user = $context->user();
                $profile = Profile::query()->where('user_id', $user->id)->first()
                    ?? throw new DomainException(RecheckEligibility::NO_PLAN);

                return EligibilityRecheckPayload::build($profile, UserDay::today($user));
            });
    }
}

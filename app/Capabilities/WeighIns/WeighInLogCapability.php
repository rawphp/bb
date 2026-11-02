<?php

namespace App\Capabilities\WeighIns;

use App\Actions\WeighIns\LogWeighIn;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\WeighIns\Data\WeighInLogInput;
use App\Models\User;
use App\Models\WeighIn;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WeighInLogCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly LogWeighIn $log) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('weigh_in_log', affects: WeighInReads::AFFECTS)
            ->description('Log a body weight the user reported, at the time they weighed in, on any day, with an optional waist measurement. Give the weight and waist in the unit the user said, tagged with it; the trend weight and Today update from it. Never log a weigh-in the user did not report. A new weigh-in takes a new id; sending the id of one the user already has changes nothing (use weigh_in_update to change it).')
            ->cli('weigh-in', 'log')
            ->input(WeighInLogInput::class)
            ->run(function (WeighInLogInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $weighIn = ($this->log)(
                    $user,
                    $this->ownedOrNew(WeighIn::class, $input->id, $user),
                    $input->id,
                    $input->weighed_at,
                    $input->weight,
                    $input->waist,
                    MeasuredBy::caller($user),
                );

                return new WriteResult(invalidates: WeighInReads::keys([$weighIn->weighed_at->format('Y-m-d')]));
            });
    }
}

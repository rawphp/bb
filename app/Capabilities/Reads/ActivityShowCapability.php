<?php

namespace App\Capabilities\Reads;

use App\Actions\Activity\ListActivity;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\ActivityShowInput;
use App\Capabilities\Reads\Data\ActivityShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ActivityShowCapability implements HostCapability
{
    public function __construct(private readonly ListActivity $listActivity) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('activity_show')
            ->description('Show the signed-in user\'s last 100 changes, newest first: what changed, through which channel (app, Coach, MCP, CLI or Health bridge), when, and whether it was done or refused. It changes nothing.')
            ->cli('activity', 'show')
            ->input(ActivityShowInput::class)
            ->output(ActivityShowOutput::class)
            ->run(fn (ActivityShowInput $input, CapabilityContext $context): ActivityShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): ActivityShowOutput
    {
        /** @var User $user */
        $user = $context->user();

        return new ActivityShowOutput(entries: ($this->listActivity)($user, CarbonImmutable::now()));
    }
}

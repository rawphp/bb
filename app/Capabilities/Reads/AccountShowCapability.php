<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\AccountShowInput;
use App\Capabilities\Reads\Data\AccountShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class AccountShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('account_show')
            ->description('Show the signed-in account: the name and email address it is registered with. Use it to check which account is signed in. It changes nothing.')
            ->cli('account', 'show')
            ->input(AccountShowInput::class)
            ->output(AccountShowOutput::class)
            ->run(fn (AccountShowInput $input, CapabilityContext $context): AccountShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): AccountShowOutput
    {
        /** @var User $user */
        $user = $context->user();

        return new AccountShowOutput(name: $user->name, email: $user->email);
    }
}

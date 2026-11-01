<?php

namespace App\Capabilities\Account;

use App\Capabilities\Account\Data\AccountShowInput;
use App\Capabilities\Account\Data\AccountShowOutput;
use App\Capabilities\HostCapability;
use App\Models\User;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class AccountShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return Capability::define('account_show')
            ->description('Show the signed-in account: the name and email address it is registered with. Use it to check which account is signed in. It changes nothing.')
            ->surfaces(['http', 'job', 'mcp', 'cli'])
            ->cli('account', 'show')
            ->input(AccountShowInput::class)
            ->output(AccountShowOutput::class)
            ->readOnly()
            ->run(fn (AccountShowInput $input, CapabilityContext $context): AccountShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): AccountShowOutput
    {
        /** @var User $user */
        $user = $context->user();

        return new AccountShowOutput(name: $user->name, email: $user->email);
    }
}

<?php

namespace App\Capabilities\Account;

use App\Actions\Account\DeleteAccount;
use App\Capabilities\Account\Data\AccountDeleteInput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class AccountDeleteCapability implements HostCapability
{
    public function __construct(
        private readonly DeleteAccount $delete,
        private readonly Auth $auth,
    ) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // The agent and every token are refused it (CAP-031): a person deletes their own account.
        return HostDefinition::write('account_delete', affects: ['account_show'], destructive: true)
            ->description('Delete the signed-in account and all its data: profile, plan, weigh-ins, workouts, meals and tokens, and sign out. It cannot be undone, so it needs the word DELETE typed as the confirmation; a subscription that would renew must be cancelled first. Only the user can call it, never the agent or a token.')
            ->cli('account', 'delete')
            ->input(AccountDeleteInput::class)
            ->run(fn (AccountDeleteInput $input, CapabilityContext $context): WriteResult => $this->run($context));
    }

    private function run(CapabilityContext $context): WriteResult
    {
        /** @var User $user */
        $user = $context->user();

        ($this->delete)($user);
        $this->endSession($user);

        return new WriteResult(invalidates: [ReadKey::for('account_show')]);
    }

    /**
     * The session that deleted the account signs out with it, as SignOutController signs out (ACC-006). Not logout():
     * that cycles the remember token by saving the user, which would insert the deleted account again.
     */
    private function endSession(User $user): void
    {
        $guard = $this->auth->guard('web');
        $request = app(Request::class);
        if ($guard->user()?->getAuthIdentifier() !== $user->getAuthIdentifier() || ! $request->hasSession()) {
            return;
        }
        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}

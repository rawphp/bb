<?php

namespace App\Capabilities\Tokens;

use App\Actions\Tokens\RevokeToken;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Tokens\Data\TokenRevokeInput;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TokenRevokeCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly RevokeToken $revoke) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('token_revoke', affects: ['token_list'])
            ->description('Revoke one of the user\'s personal access tokens, so it stops working at once. Use it when the user removes a token or disconnects the Health bridge. It cannot be undone.')
            ->cli('token', 'revoke')
            ->input(TokenRevokeInput::class)
            ->run(fn (TokenRevokeInput $input, CapabilityContext $context): WriteResult => $this->run($input, $context));
    }

    public function owned(User $user): array
    {
        return ['id' => PersonalAccessToken::ownedBy($user)];
    }

    private function run(TokenRevokeInput $input, CapabilityContext $context): WriteResult
    {
        /** @var User $user */
        $user = $context->user();

        ($this->revoke)($user, $input->id);

        return new WriteResult(invalidates: [ReadKey::for('token_list')], toast: 'Token revoked');
    }
}

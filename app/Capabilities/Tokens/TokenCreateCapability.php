<?php

namespace App\Capabilities\Tokens;

use App\Actions\Tokens\CreateToken;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Tokens\Data\CreatedToken;
use App\Capabilities\Tokens\Data\TokenCapability;
use App\Capabilities\Tokens\Data\TokenCreateInput;
use App\Capabilities\Tokens\Data\TokenCreateOutput;
use App\Domain\Tokens\TokenScope;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TokenCreateCapability implements HostCapability
{
    public function __construct(
        private readonly CreateToken $create,
        private readonly TokenCatalog $catalog,
    ) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('token_create', affects: ['token_list'])
            ->description('Create a personal access token for MCP, the CLI or the Health bridge, with a name, an expiry and a scope. Only the signed-in user can call it, never a token (CAP-031). It returns the token\'s secret, which is shown this once.')
            // CAP-003 maps every capability to a CLI command. With a token, this one answers 403.
            ->cli('token', 'create')
            ->input(TokenCreateInput::class)
            ->output(TokenCreateOutput::class)
            ->run(fn (TokenCreateInput $input, CapabilityContext $context): TokenCreateOutput => $this->run($input, $context));
    }

    private function run(TokenCreateInput $input, CapabilityContext $context): TokenCreateOutput
    {
        /** @var User $user */
        $user = $context->user();

        [$token, $secret] = ($this->create)(
            $user,
            $input->id,
            $input->name,
            $input->expires_in_days,
            TokenScope::from($input->scope),
            array_map(fn (TokenCapability $capability): string => $capability->name, $input->capabilities),
            $this->catalog->reads(),
            $this->catalog->names(),
        );

        return new TokenCreateOutput(
            token: new CreatedToken(
                id: $token->id,
                name: $token->name,
                plain_text: $secret,
                scope_text: $token->scope->label(),
                expires_text: TokenRows::expiresText($token),
            ),
            result: new WriteResult(invalidates: [ReadKey::for('token_list')]),
        );
    }
}

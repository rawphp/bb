<?php

namespace App\Capabilities\Tokens;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Tokens\Data\TokenCapabilityRow;
use App\Capabilities\Tokens\Data\TokenExpiry;
use App\Capabilities\Tokens\Data\TokenListInput;
use App\Capabilities\Tokens\Data\TokenListOutput;
use App\Capabilities\Tokens\Data\TokenScope as TokenScopeRow;
use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TokenListCapability implements HostCapability
{
    /** @var array<int, string> days => label (CAP-021) */
    private const EXPIRIES = [30 => '30 days', 90 => '90 days', 365 => '1 year'];

    public function __construct(private readonly TokenCatalog $catalog) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('token_list')
            ->description('List the user\'s personal access tokens, with each one\'s scope, expiry and when it was last used, never its secret. It also lists the scopes, expiries and capabilities a new token can have. It changes nothing.')
            ->cli('token', 'list')
            ->input(TokenListInput::class)
            ->output(TokenListOutput::class)
            ->run(fn (TokenListInput $input, CapabilityContext $context): TokenListOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): TokenListOutput
    {
        /** @var User $user */
        $user = $context->user();

        $tokens = PersonalAccessToken::ownedBy($user)->latest()->orderByDesc('id')->get()
            ->map(fn (PersonalAccessToken $token) => TokenRows::row($token))
            ->values()
            ->all();

        return new TokenListOutput(
            tokens: $tokens,
            scopes: array_map(
                fn (TokenScope $scope): TokenScopeRow => new TokenScopeRow($scope->value, $scope->label(), $scope->description()),
                TokenScope::cases(),
            ),
            expiries: array_map(
                fn (int $days, string $label): TokenExpiry => new TokenExpiry($days, $label),
                array_keys(self::EXPIRIES),
                self::EXPIRIES,
            ),
            capabilities: array_values(array_map(
                fn (CapabilityDefinition $definition): TokenCapabilityRow => new TokenCapabilityRow($definition->name, $definition->description),
                $this->catalog->callable(),
            )),
        );
    }
}

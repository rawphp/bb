<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** token_list: the user's tokens and what a new one can be (CAP-021). */
final class TokenListOutput extends CapabilityData
{
    /**
     * @param  list<TokenRow>  $tokens
     * @param  list<TokenScope>  $scopes
     * @param  list<TokenExpiry>  $expiries
     * @param  list<TokenCapabilityRow>  $capabilities
     */
    public function __construct(
        #[Field(items: TokenRow::class)]
        public readonly array $tokens,
        #[Field(items: TokenScope::class)]
        public readonly array $scopes,
        #[Field(items: TokenExpiry::class)]
        public readonly array $expiries,
        #[Field(items: TokenCapabilityRow::class)]
        public readonly array $capabilities,
    ) {}
}

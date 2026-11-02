<?php

namespace App\Capabilities\Tokens\Data;

use App\Capabilities\Tokens\TokenCatalog;
use App\Domain\Tokens\TokenScope;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * token_create's `capabilities` (CAP-021, CAP-031): a custom scope picks at least one,
 * each one a token may call, and the other scopes pick none. It runs with the input's
 * rules, so a refusal is a 422 that names the field before anything is stored.
 */
final class TokenPicks implements DataAwareRule, ValidationRule
{
    /** Runs on an empty or missing list too, which is exactly what a custom scope refuses. */
    public bool $implicit = true;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $picked = array_column(array_filter(is_array($value) ? $value : [], 'is_array'), 'name');
        $custom = ($this->data['scope'] ?? null) === TokenScope::Custom->value;

        if ($custom && $picked === []) {
            $fail('Pick at least one action for a custom token.');

            return;
        }

        if (! $custom && $picked !== []) {
            $fail('Only a custom token takes a list of actions.');

            return;
        }

        $unknown = array_diff($picked, app(TokenCatalog::class)->names());
        if ($unknown !== []) {
            $fail(sprintf('A token can\'t use %s.', implode(', ', $unknown)));
        }
    }
}

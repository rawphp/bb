<?php

namespace App\Capabilities\Nutrition\Data;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * FOOD-060: a serving cannot hold more of one macro than it weighs. That also keeps the
 * food's values per 100 g at 100 g or less, inside the foods table's decimal(7,2).
 */
final class WithinServing implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $label) {}

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $grams = $this->data['serving_grams'] ?? null;
        if (is_numeric($value) && is_numeric($grams) && (float) $value > (float) $grams) {
            $fail("{$this->label} can't weigh more than the serving.");
        }
    }
}

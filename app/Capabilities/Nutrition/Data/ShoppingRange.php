<?php

namespace App\Capabilities\Nutrition\Data;

/**
 * The date range a shopping input names (SHOP-001): both ends or neither, the last day not before the
 * first. The schema decides whether the range may be left out.
 */
final class ShoppingRange
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'required_with:to'],
            'to' => ['nullable', 'required_with:from', 'after_or_equal:from'],
        ];
    }
}

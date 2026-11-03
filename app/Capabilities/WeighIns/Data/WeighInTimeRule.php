<?php

namespace App\Capabilities\WeighIns\Data;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * The date-time format check is a pattern, so 30 February, 24:30 or second 60 would pass it and roll over to
 * another day or minute when parsed. A weigh-in's time must read back as it was sent, inside what the weigh_ins
 * timestamp column holds (in UTC, a day inside each end).
 */
final class WeighInTimeRule implements ValidationRule
{
    public const MESSAGE = 'Enter a real date and time.';

    private const FIRST = '1970-01-02 00:00:00';

    private const LAST = '2038-01-18 00:00:00';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::real($value)) {
            $fail(self::MESSAGE);
        }
    }

    private static function real(string $value): bool
    {
        try {
            $at = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return false;
        }
        if ($at->format('Y-m-d\TH:i') !== substr($value, 0, 16)) {
            return false;
        }
        $utc = $at->utc()->format('Y-m-d H:i:s');

        return $utc >= self::FIRST && $utc <= self::LAST;
    }
}

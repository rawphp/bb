<?php

use App\Support\Env;

/*
| Per-user agent caps (AGT-005). Empty means no cap, the self-hosted default;
| anything but a whole number stops the app from booting.
| A day runs in the user's time zone (SET-012); a month runs in the app timezone.
| A user can stretch the daily cap by changing time zone; the monthly token cap
| is what bounds the bill.
*/

$cap = static function (string $key): ?int {
    $value = Env::raw($key);
    if ($value === null) {
        return null;
    }

    // A typo must not lift the cap or block everyone: refuse to boot instead.
    if (! ctype_digit(trim($value))) {
        throw new InvalidArgumentException("{$key} must be a whole number of 0 or more, or empty for no cap; it is \"{$value}\".");
    }

    return (int) trim($value);
};

return [
    'turns_per_day' => $cap('AGENT_TURNS_PER_DAY'),
    'tokens_per_month' => $cap('AGENT_TOKENS_PER_MONTH'),
];

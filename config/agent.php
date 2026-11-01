<?php

/*
| Per-user agent caps (AGT-005). Empty means no cap, the self-hosted default.
| A day and a month run in the app timezone.
*/

$cap = static function (string $key): ?int {
    $value = env($key);

    return is_numeric($value) ? max((int) $value, 0) : null;
};

return [
    'turns_per_day' => $cap('AGENT_TURNS_PER_DAY'),
    'tokens_per_month' => $cap('AGENT_TOKENS_PER_MONTH'),
];

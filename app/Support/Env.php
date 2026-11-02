<?php

namespace App\Support;

/**
 * The one environment lookup the config files share: `$_ENV`, then `$_SERVER`,
 * then `getenv()`, so `config:cache` keeps working without Laravel's helper
 * (docs/architecture.md). Each file still parses the value its own way.
 */
final class Env
{
    /** The raw value, or null when it is unset or empty. */
    public static function raw(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === '' ? null : (string) $value;
    }
}

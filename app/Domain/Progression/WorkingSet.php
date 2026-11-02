<?php

namespace App\Domain\Progression;

/**
 * WO-025: every logged set counts as a working set, and a skipped set never does. Each type that holds a set's
 * logged and skipped flags answers from here; nothing else spells the rule out.
 */
final class WorkingSet
{
    public static function counts(bool $isLogged, bool $isSkipped): bool
    {
        return $isLogged && ! $isSkipped;
    }
}

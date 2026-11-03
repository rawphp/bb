<?php

namespace App\Actions\Account;

use App\Capabilities\Support\FieldRefused;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * SET-012: stores the IANA time zone the user's days are counted in. Only a name PHP knows is taken, including the
 * backward-compatible links Chrome reports for some devices (Asia/Calcutta, Europe/Kiev) and the few legacy names PHP
 * still lists (EST, MST); an offset such as +10:00, or an abbreviation PHP does not list such as AEST, is refused.
 */
final class SetTimeZone
{
    public const UNKNOWN = 'Send an IANA time zone name, such as Australia/Brisbane.';

    /** @return bool whether the stored zone changed */
    public function __invoke(User $user, string $timeZone): bool
    {
        if (! in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new FieldRefused('time_zone', self::UNKNOWN);
        }
        if ($user->time_zone === $timeZone) {
            return false;
        }

        $user->forceFill(['time_zone' => $timeZone, 'time_zone_changed_at' => CarbonImmutable::now()])->save();

        return true;
    }
}

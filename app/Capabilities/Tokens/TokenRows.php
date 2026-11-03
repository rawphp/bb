<?php

namespace App\Capabilities\Tokens;

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Capabilities\Tokens\Data\TokenRow;
use App\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** A token as Settings lists it: words ready to show, and a decided status (CAP-015, CAP-018). */
final class TokenRows
{
    /** A token this close to its expiry shows a warning. */
    private const WARN_DAYS = 7;

    public static function row(PersonalAccessToken $token): TokenRow
    {
        return new TokenRow(
            id: $token->id,
            name: $token->name,
            scope_text: $token->scope->label(),
            created_text: 'Created '.self::date($token->created_at),
            expires_text: self::expiresText($token),
            last_used_text: self::lastUsedText($token->last_used_at),
            status: self::status($token->expires_at),
        );
    }

    public static function expiresText(PersonalAccessToken $token): string
    {
        $expires = $token->expires_at;
        if ($expires === null) {
            return 'Never expires';
        }

        return ($expires->isPast() ? 'Expired ' : 'Expires ').self::date($expires);
    }

    public static function lastUsedText(?CarbonInterface $used): string
    {
        if ($used === null) {
            return 'Never used';
        }

        return 'Last used '.($used->isToday() ? 'today, '.$used->format('H:i') : self::date($used));
    }

    private static function status(?CarbonInterface $expires): Status
    {
        if ($expires === null) {
            return new Status('Active', Tone::Good->value);
        }

        if ($expires->isPast()) {
            return new Status('Expired', Tone::Neutral->value);
        }

        $days = (int) CarbonImmutable::now()->startOfDay()->diffInDays($expires->copy()->startOfDay());
        if ($days <= self::WARN_DAYS) {
            return new Status(match ($days) {
                0 => 'Expires today',
                1 => 'Expires tomorrow',
                default => "Expires in {$days} days",
            }, Tone::Warning->value);
        }

        return new Status('Active', Tone::Good->value);
    }

    private static function date(?CarbonInterface $date): string
    {
        return $date?->format('j F Y') ?? '';
    }
}

<?php

namespace App\Agent;

/**
 * The CAP-031 exclusion list: capabilities the agent may not call, each with
 * its reason. Everything else registered on the agent surface is a tool.
 */
final class Exclusions
{
    /** @var array<string, string> capability name => reason */
    public const NAMES = [
        'account_delete' => 'A person must delete their own account (ACC-005).',
        'token_create' => 'An agent must not mint its own credentials.',
        'token_revoke' => 'An agent must not manage credentials.',
    ];

    /** @var array<string, string> name prefix => reason */
    public const PREFIXES = [
        'billing_' => 'Money needs a person.',
        'coach_' => 'The Coach\'s own settings and history belong to the person.',
    ];

    public static function reason(string $capability): ?string
    {
        if (isset(self::NAMES[$capability])) {
            return self::NAMES[$capability];
        }

        foreach (self::PREFIXES as $prefix => $reason) {
            if (str_starts_with($capability, $prefix)) {
                return $reason;
            }
        }

        return null;
    }
}

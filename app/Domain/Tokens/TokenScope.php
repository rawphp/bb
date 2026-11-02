<?php

namespace App\Domain\Tokens;

use InvalidArgumentException;

/**
 * What a personal access token may call (CAP-021, CAP-023). A token's
 * abilities are capability names; "Everything" is Sanctum's `*`.
 */
enum TokenScope: string
{
    case Everything = 'everything';
    case ReadOnly = 'read_only';
    case HealthBridge = 'health_bridge';
    case Custom = 'custom';

    /** The two capabilities the Health bridge Shortcut posts to (INT-001). */
    public const HEALTH_BRIDGE = ['weigh_in_log', 'steps_log'];

    public function label(): string
    {
        return match ($this) {
            self::Everything => 'Everything',
            self::ReadOnly => 'Read only',
            self::HealthBridge => 'Health bridge',
            self::Custom => 'Custom',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Everything => 'Reads and changes everything you can.',
            self::ReadOnly => 'Reads everything and changes nothing.',
            self::HealthBridge => 'Logs weigh-ins and steps only.',
            self::Custom => 'Only the actions you pick.',
        };
    }

    /**
     * The token's abilities. "Read only" is every read that exists when the token is made.
     *
     * @param  list<string>  $reads  every read capability's name
     * @param  list<string>  $picked  the custom scope's capability names
     * @param  list<string>  $allowed  every capability a token may call
     * @return list<string>
     */
    public function abilities(array $reads, array $picked, array $allowed): array
    {
        return match ($this) {
            self::Everything => ['*'],
            self::ReadOnly => self::sorted($reads),
            self::HealthBridge => self::HEALTH_BRIDGE,
            self::Custom => self::custom($picked, $allowed),
        };
    }

    /**
     * @param  list<string>  $picked
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function custom(array $picked, array $allowed): array
    {
        if ($picked === []) {
            throw new InvalidArgumentException('Pick at least one action for a custom token.');
        }

        $unknown = array_diff($picked, $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('A token can\'t use %s.', implode(', ', $unknown)));
        }

        return self::sorted($picked);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}

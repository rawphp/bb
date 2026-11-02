<?php

namespace App\Capabilities\Support;

use Laravel\Sanctum\PersonalAccessToken;
use UnexpectedValueException;

/**
 * Which way a write came in (CAP-022). The server derives it from the caller
 * the adapter set and, over http, from the credential: never from the client.
 */
enum Channel: string
{
    case Ui = 'ui';
    case Agent = 'agent';
    case Mcp = 'mcp';
    case Cli = 'cli';
    case Bridge = 'bridge';

    /** The abilities a Health bridge token is limited to (INT-001). */
    public const BRIDGE_ABILITIES = ['weigh_in_log', 'steps_log'];

    /**
     * Null for the server itself: CAP-022's five channels are ways a person reaches the
     * app, and a queued job or an artisan command is neither the user nor the Coach. Its
     * row is still audited, with the user, but Activity does not list it under a channel
     * the user never used.
     *
     * @param  string  $caller  the invoke's caller: http, agent, mcp, cli, artisan or job
     * @param  object|null  $token  over http, the signed-in user's current access token
     */
    public static function derive(string $caller, ?object $token): ?self
    {
        return match ($caller) {
            // The in-app agent's tool calls invoke as caller=agent (AGT-004).
            'agent' => self::Agent,
            'mcp' => self::Mcp,
            'cli' => self::Cli,
            'http' => self::overHttp($token),
            'job', 'artisan' => null,
            // A new caller needs a decision here, not a guess that would show it as the app.
            default => throw new UnexpectedValueException("No audit channel for caller \"{$caller}\"."),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ui => 'App',
            self::Agent => 'Coach',
            self::Mcp => 'MCP',
            self::Cli => 'CLI',
            self::Bridge => 'Health bridge',
        };
    }

    /** The session is the app; a personal access token is the bridge when limited to its abilities, else the CLI. */
    private static function overHttp(?object $token): self
    {
        if (! $token instanceof PersonalAccessToken) {
            return self::Ui;
        }

        $abilities = (array) $token->abilities;

        return $abilities !== [] && array_diff($abilities, self::BRIDGE_ABILITIES) === []
            ? self::Bridge
            : self::Cli;
    }
}

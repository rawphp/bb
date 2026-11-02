<?php

namespace App\Capabilities\Support;

use Laravel\Sanctum\PersonalAccessToken;

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
     * @param  string  $caller  the invoke's caller: http, agent, mcp, cli, artisan or job
     * @param  object|null  $token  over http, the signed-in user's current access token
     */
    public static function derive(string $caller, ?object $token): self
    {
        return match ($caller) {
            // The in-app agent's tool calls invoke as caller=agent (AGT-004). CAP-022 has
            // five channels and no server one, so a queued job acting for the user counts
            // as the agent: it is not the user at the app, a script or the bridge.
            'agent', 'job' => self::Agent,
            'mcp' => self::Mcp,
            'cli', 'artisan' => self::Cli,
            default => self::overHttp($token),
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

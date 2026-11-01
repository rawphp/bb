<?php

/**
 * Load a published config file with the given env keys unset, as on a fresh instance.
 *
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function configWithout(string $file, array $keys): array
{
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($saved as $key => [$env, $server, $process]) {
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($process !== false) {
                putenv("{$key}={$process}");
            }
        }
    }
}

it('AGT-005 publishes the capabilities-ai config with the 17-agent.md setup defaults', function () {
    $config = configWithout('capabilities-ai.php', [
        'CAPABILITIES_AI_PROGRESS_DRIVER', 'CAPABILITIES_AI_LLM_DRIVER', 'CAPABILITIES_AI_CLAIM_TTL',
        'CAPABILITIES_AI_QUEUE_NAME', 'CAPABILITIES_AI_PROPOSALS_ENABLED', 'CAPABILITIES_AI_ANTHROPIC_MODEL',
        'CAPABILITIES_AI_ROUTES_ENABLED',
    ]);

    expect($config['progress']['driver'])->toBe('redis')
        ->and($config['llm']['driver'])->toBe('anthropic')
        ->and($config['claim_ttl'])->toBe(1800)
        ->and($config['queue']['name'])->toBe('agent')
        ->and($config['proposals']['enabled'])->toBeFalse()
        ->and($config['llm']['anthropic']['model'])->toBe('claude-opus-5-5')
        ->and($config['routes']['enabled'])->toBeTrue();
});

it('AGT-002 keeps the fake LLM driver and the array progress store to tests', function () {
    expect(config('capabilities-ai.llm.driver'))->toBe('fake')
        ->and(config('capabilities-ai.progress.driver'))->toBe('array');
});

it('AGT-005 sets no per-user cap unless the instance configures one', function () {
    $config = configWithout('agent.php', ['AGENT_TURNS_PER_DAY', 'AGENT_TOKENS_PER_MONTH']);

    expect($config)->toBe(['turns_per_day' => null, 'tokens_per_month' => null]);
});

it('AGT-004 opens the core agent surface unless CAPABILITIES_SURFACE_AGENT turns it off', function () {
    expect(configWithout('capabilities.php', ['CAPABILITIES_SURFACE_AGENT'])['surfaces']['agent'])
        ->toMatchArray(['enabled' => true, 'require_package' => false]);

    putenv('CAPABILITIES_SURFACE_AGENT=false');
    try {
        expect((require config_path('capabilities.php'))['surfaces']['agent']['enabled'])->toBeFalse();
    } finally {
        putenv('CAPABILITIES_SURFACE_AGENT');
    }
});

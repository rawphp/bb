<?php

namespace Tests\Support;

use Illuminate\Support\ServiceProvider;
use Rawphp\CapabilitiesAi\Contracts\LlmClient;
use Rawphp\CapabilitiesAi\Contracts\ProgressStore;

/**
 * Puts the scripted Coach in the e2e server (tests/e2e/router.php registers it). The Coach is on only while the
 * script file exists, so a run with no script behaves as an instance with the agent off.
 */
final class ScriptedCoachProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, ScriptedCoach::class);
        $this->app->singleton(ProgressStore::class, CacheProgressStore::class);
    }

    public function boot(): void
    {
        if (is_file(ScriptedCoach::path())) {
            config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'e2e']);
        }
    }
}

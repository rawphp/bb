<?php

namespace App\Providers;

use App\Agent\ConversationHistoryContext;
use App\Agent\MeteredTurnClaim;
use App\Agent\RegistryToolCatalog;
use App\Capabilities\HostAuthorizer;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\AuditRecordWriter;
use App\Capabilities\Support\HostBus;
use App\Capabilities\Support\PrefetchesKeys;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Rawphp\Capabilities\Adapters\Artisan\IntegrationHealthCommand;
use Rawphp\Capabilities\Contracts\AuditWriter;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ConversationContextProvider;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;
use Rawphp\CapabilitiesAi\Contracts\TurnClaim;
use Rawphp\CapabilitiesAi\Support\EloquentTurnClaim;

/**
 * The host side of rawphp/laravel-capabilities. Registered last.
 *
 * Registration has one path: every app/Capabilities/<Module>/<StudlyName>Capability.php,
 * loaded in sorted order. A duplicate name fails boot. A class that implements
 * PrefetchesKeys is also tagged with it, for sync_manifest (OFF-004).
 */
final class CapabilitiesHostProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Authorizer::class, HostAuthorizer::class);

        // Replaces the package's outbox writer: Activity reads these rows (CAP-022).
        $this->app->singleton(AuditWriter::class, AuditRecordWriter::class);

        // Replaces the package's alias of CapabilityBus to the registry (CAP-012, CAP-014).
        $this->app->singleton(CapabilityBus::class, fn (Container $app): HostBus => new HostBus($app->make(CapabilityRegistry::class)));

        // The agent's seams in capabilities-ai (AGT-003, AGT-011).
        $this->app->singleton(ToolCatalog::class, RegistryToolCatalog::class);
        $this->app->singleton(ConversationContextProvider::class, ConversationHistoryContext::class);
        // Replaces the package's claim: the same transitions, plus each turn's token total (AGT-005).
        $this->app->singleton(TurnClaim::class, fn (): MeteredTurnClaim => new MeteredTurnClaim(new EloquentTurnClaim));
    }

    public function boot(CapabilityRegistry $registry): void
    {
        self::registerFrom(app_path('Capabilities'), 'App\\Capabilities', $registry, $this->app);

        // The package registers it only with the artisan surface on, which stays off.
        if ($this->app->runningInConsole()) {
            $this->commands([IntegrationHealthCommand::class]);
        }
    }

    /**
     * Register every <dir>/<Module>/<Name>Capability.php class into the registry.
     *
     * @return list<string> the capability names registered, in load order
     */
    public static function registerFrom(string $dir, string $namespace, CapabilityRegistry $registry, Container $container): array
    {
        $files = glob($dir.'/*/*Capability.php') ?: [];
        sort($files);

        $names = [];
        foreach ($files as $file) {
            $class = $namespace.'\\'.basename(dirname($file)).'\\'.basename($file, '.php');
            $capability = $container->make($class);

            if (! $capability instanceof HostCapability) {
                throw new LogicException(sprintf('%s must implement %s.', $class, HostCapability::class));
            }

            $names[] = $capability->definition()->register($registry)->name;

            if ($capability instanceof PrefetchesKeys) {
                $container->tag([$class], PrefetchesKeys::class);
            }
        }

        return $names;
    }
}

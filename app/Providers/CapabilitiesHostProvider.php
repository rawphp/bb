<?php

namespace App\Providers;

use App\Capabilities\HostAuthorizer;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\AgentPolicy;
use App\Capabilities\Support\BillingGate;
use App\Capabilities\Support\HostBus;
use App\Capabilities\Support\NoAgentPolicy;
use App\Capabilities\Support\NoBilling;
use App\Capabilities\Support\Ownership;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\PrefetchesKeys;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

/**
 * The host side of rawphp/laravel-capabilities. Registered last.
 *
 * Registration has one path: every app/Capabilities/<Module>/<StudlyName>Capability.php,
 * loaded in sorted order. A duplicate name fails boot. A class that implements
 * PrefetchesKeys is also tagged with it, for sync_manifest (OFF-004). One that
 * implements OwnsInput declares its row IDs to the authorizer (CAP-020 step 2).
 *
 * Billing and the agent policy default to allowing everything. BILLING_API and
 * COACH_CTX bind their own BillingGate and AgentPolicy.
 */
final class CapabilitiesHostProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ownership::class);
        $this->app->singletonIf(BillingGate::class, NoBilling::class);
        $this->app->singletonIf(AgentPolicy::class, NoAgentPolicy::class);
        $this->app->singleton(Authorizer::class, HostAuthorizer::class);

        // Replaces the package's alias of CapabilityBus to the registry (CAP-012, CAP-014).
        $this->app->singleton(CapabilityBus::class, fn (Container $app): HostBus => new HostBus($app->make(CapabilityRegistry::class), $app->make(Ownership::class)));
    }

    public function boot(CapabilityRegistry $registry): void
    {
        self::registerFrom(app_path('Capabilities'), 'App\\Capabilities', $registry, $this->app);
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

            $name = $capability->definition()->register($registry)->name;
            $names[] = $name;

            if ($capability instanceof OwnsInput) {
                $container->make(Ownership::class)->declare($name, $capability);
            }

            if ($capability instanceof PrefetchesKeys) {
                $container->tag([$class], PrefetchesKeys::class);
            }
        }

        return $names;
    }
}

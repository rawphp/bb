<?php

namespace App\Providers;

use App\Capabilities\HostAuthorizer;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostBus;
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
 * loaded in sorted order. A duplicate name fails boot.
 */
final class CapabilitiesHostProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Authorizer::class, HostAuthorizer::class);

        // Replaces the package's alias of CapabilityBus to the registry (CAP-012, CAP-014).
        $this->app->singleton(CapabilityBus::class, fn (Container $app): HostBus => new HostBus($app->make(CapabilityRegistry::class)));
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

            $names[] = $capability->definition()->register($registry)->name;
        }

        return $names;
    }
}

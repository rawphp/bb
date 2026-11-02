<?php

namespace App\Providers;

use App\Agent\CoachContext;
use App\Agent\ConfirmationPolicy;
use App\Agent\MeteredTurnClaim;
use App\Agent\RegistryToolCatalog;
use App\Billing\StripeClientGateway;
use App\Billing\StripeGateway;
use App\Capabilities\HostAuthorizer;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\AgentPolicy;
use App\Capabilities\Support\AuditRecordWriter;
use App\Capabilities\Support\BillingGate;
use App\Capabilities\Support\BillingOnly;
use App\Capabilities\Support\HostBus;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\NoBilling;
use App\Capabilities\Support\Ownership;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\PrefetchesKeys;
use App\Capabilities\Support\ShownOnceIdempotencyStore;
use App\Capabilities\Support\StripeBilling;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Rawphp\Capabilities\Adapters\Artisan\IntegrationHealthCommand;
use Rawphp\Capabilities\Contracts\AuditWriter;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Contracts\IdempotencyStore;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ConversationContextProvider;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;
use Rawphp\CapabilitiesAi\Contracts\TurnClaim;
use Rawphp\CapabilitiesAi\Support\EloquentTurnClaim;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * The host side of rawphp/laravel-capabilities. Registered last.
 *
 * Registration has one path: every app/Capabilities/<Module>/<StudlyName>Capability.php,
 * loaded in sorted order. A duplicate name fails boot. A class that implements
 * PrefetchesKeys is also tagged with it, for sync_manifest (OFF-004). One that
 * implements OwnsInput declares its row IDs to the authorizer (CAP-020 step 2).
 *
 * Billing defaults to allowing everything: BILLING_API binds its own BillingGate. The agent
 * policy is the Coach's {@see ConfirmationPolicy}.
 */
final class CapabilitiesHostProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ownership::class);
        $this->app->singletonIf(BillingGate::class, config('billing.enabled') ? StripeBilling::class : NoBilling::class);
        $this->app->singletonIf(StripeGateway::class, function (): StripeClientGateway {
            // Short timeouts and no retry: a slow Stripe fails the call rather than hold a request for 80 seconds.
            $http = CurlClient::instance();
            $http->setConnectTimeout(5);
            $http->setTimeout(15);
            ApiRequestor::setHttpClient($http);

            return new StripeClientGateway(new StripeClient(['api_key' => (string) config('billing.stripe.secret'), 'max_network_retries' => 0]));
        });
        $this->app->singleton(AgentPolicy::class, ConfirmationPolicy::class);
        $this->app->singleton(Authorizer::class, HostAuthorizer::class);

        // Replaces the package's outbox writer: Activity reads these rows (CAP-022).
        $this->app->singleton(AuditWriter::class, AuditRecordWriter::class);

        // Replaces the package's alias of CapabilityBus to the registry (CAP-012, CAP-014).
        $this->app->singleton(CapabilityBus::class, fn (Container $app): HostBus => new HostBus($app->make(CapabilityRegistry::class), $app->make(Ownership::class)));

        // A replay never shows a secret again, so the store never keeps one (CAP-014, CAP-021).
        $this->app->extend(IdempotencyStore::class, fn (IdempotencyStore $store): ShownOnceIdempotencyStore => new ShownOnceIdempotencyStore($store));

        // The agent's seams in capabilities-ai (AGT-003, AGT-011).
        $this->app->singleton(ToolCatalog::class, RegistryToolCatalog::class);
        $this->app->singleton(ConversationContextProvider::class, CoachContext::class);
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

            if (is_subclass_of($class, BillingOnly::class) && ! $container->make('config')->get('billing.enabled')) {
                continue;
            }

            $capability = $container->make($class);

            if (! $capability instanceof HostCapability) {
                throw new LogicException(sprintf('%s must implement %s.', $class, HostCapability::class));
            }

            $name = HostDefinition::register($capability->definition(), $registry)->name;
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

<?php

namespace Tests;

use App\Support\RequestClock;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use RawPHP\Warp\Concerns\InteractsWithWarmApplication;
use RawPHP\Warp\ResetManifest;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithWarmApplication {
        createApplication as createWarmApplication;
    }

    public function createApplication(): Application
    {
        $app = $this->createWarmApplication();

        // migrate:fresh can purge the default connection. The capability
        // package's stores keep the Connection they resolved at boot, and their
        // first query reconnects it through the manager, which disconnects the
        // live connection and silently rolls back the test's RefreshDatabase
        // transaction. Reconnect it as soon as the migration ends, before that
        // transaction begins.
        $app->make('events')->listen(DatabaseRefreshed::class, fn () => self::reconnectCapabilityStores($app));
        // A warp-isolated test boots a fresh app and finds the database already migrated, so no
        // DatabaseRefreshed fires: reconnect now too, still before the transaction begins.
        self::reconnectCapabilityStores($app);

        return $app;
    }

    private static function reconnectCapabilityStores(Application $app): void
    {
        $connection = $app->make(CapabilityRegistry::class)->transactionConnection();

        if ($connection instanceof Connection) {
            // Reconnect it on its own: through the manager, a reconnect disconnects the live
            // connection the test's transaction is open on.
            $connection->setReconnector(function (Connection $stale) use ($app): void {
                $fresh = $app->make('db.factory')->make($app['config']['database.connections.'.$stale->getName()], $stale->getName());
                $stale->setPdo($fresh->getRawPdo())->setReadPdo($fresh->getRawReadPdo());
            });
            $connection->reconnectIfMissingConnection();
        }
    }

    protected function createClassicApplication(): Application
    {
        return parent::createApplication();
    }

    protected function warpResetManifest(): ResetManifest
    {
        // Each test's teardown clears static queue payload hooks; the warm app boots only once.
        return ResetManifest::default()->add(fn () => RequestClock::recordInPayloads());
    }
}

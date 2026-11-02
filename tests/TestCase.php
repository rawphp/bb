<?php

namespace Tests;

use App\Support\RequestClock;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use RawPHP\Warp\Concerns\InteractsWithWarmApplication;
use RawPHP\Warp\ResetManifest;
use RawPHP\Warp\WarmApplicationFactory;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithWarmApplication {
        createApplication as createWarmApplication;
    }

    public function createApplication(): Application
    {
        $boots = WarmApplicationFactory::bootCount();
        $app = $this->createWarmApplication();

        // A warp-isolated test boots its own application, and its DatabaseServiceProvider
        // points Eloquent's static connection resolver at that application's `db`. That
        // application is flushed when the test ends, but the static outlives it, so the next
        // warm test in the same worker would reach a dead container ("Target class [config]
        // does not exist"). Point it at this sandbox's `db`, which Warp re-points per test.
        Model::setConnectionResolver($app->make('db'));

        // migrate:fresh can purge the default connection. The capability
        // package's stores keep the Connection they resolved at boot, and their
        // first query reconnects it through the manager, which disconnects the
        // live connection and silently rolls back the test's RefreshDatabase
        // transaction. Reconnect it as soon as the migration ends, before that
        // transaction begins.
        $app->make('events')->listen(DatabaseRefreshed::class, fn () => self::reconnectPackageStores($app));

        // An isolated app, or a warm base booted after one, boots before Warp points it at
        // the snapshot database, so a connection resolved during boot still has the old
        // settings. The warm base usually loses it to the first test's migrate:fresh, but
        // after an isolated test the database is already migrated. Drop it, and reconnect the
        // package's stores now: no migrate:fresh will, and their first query would otherwise
        // reconnect mid-test and roll back its RefreshDatabase transaction.
        if ($this->warpShouldIsolate() || WarmApplicationFactory::bootCount() !== $boots) {
            $app['db']->purge();
            self::reconnectPackageStores($app);
        }

        return $app;
    }

    private static function reconnectPackageStores(Application $app): void
    {
        $connection = $app->make(CapabilityRegistry::class)->transactionConnection();

        if ($connection instanceof Connection) {
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

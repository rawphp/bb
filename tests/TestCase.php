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
        $app->make('events')->listen(DatabaseRefreshed::class, function () use ($app): void {
            $connection = $app->make(CapabilityRegistry::class)->transactionConnection();

            if ($connection instanceof Connection) {
                $connection->reconnectIfMissingConnection();
            }
        });

        return $app;
    }

    protected function createClassicApplication(): Application
    {
        return parent::createApplication();
    }

    protected function warpResetManifest(): ResetManifest
    {
        // Each test's teardown clears static queue payload hooks; the warm app boots only once.
        return ResetManifest::default()
            ->add(fn () => RequestClock::recordInPayloads())
            ->add(fn (Application $sandbox, Application $base) => self::restoreConnectionResolver($base));
    }

    /**
     * A warp-isolated test boots a cold app, whose DatabaseServiceProvider points Eloquent's static
     * connection resolver at that app's manager. Once the isolated test ends, that app is flushed, and
     * the next warm test in the same worker failed with "Target class [config] does not exist" on its
     * first query. Point the resolver back at the warm base's manager before every warm test.
     */
    public static function restoreConnectionResolver(Application $base): void
    {
        Model::setConnectionResolver($base->make('db'));
    }
}

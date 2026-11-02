<?php

namespace Tests;

use App\Support\RequestClock;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RawPHP\Warp\Concerns\InteractsWithWarmApplication;
use RawPHP\Warp\ResetManifest;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithWarmApplication {
        createApplication as warpCreateApplication;
    }

    public function createApplication(): Application
    {
        $app = $this->warpCreateApplication();

        // An isolated app boots before Warp points it at the snapshot database, so a
        // connection resolved during boot still has the old settings. Drop it.
        if ($this->warpShouldIsolate()) {
            $app['db']->purge();
        }

        return $app;
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

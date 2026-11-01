<?php

namespace Tests;

use App\Support\RequestClock;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RawPHP\Warp\Concerns\InteractsWithWarmApplication;
use RawPHP\Warp\ResetManifest;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithWarmApplication;

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

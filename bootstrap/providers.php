<?php

use App\Providers\AppServiceProvider;
use App\Providers\CapabilitiesHostProvider;

return [
    AppServiceProvider::class,
    // Last: package providers have booted, so the registry is ready.
    CapabilitiesHostProvider::class,
];

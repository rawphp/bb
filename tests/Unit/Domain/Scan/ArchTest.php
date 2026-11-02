<?php

arch('app/Domain/Scan is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Scan')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

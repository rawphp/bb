<?php

arch('app/Domain/Weight is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Weight')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

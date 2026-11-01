<?php

arch('app/Domain/Plan is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Plan')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

<?php

arch('app/Domain/Recipe is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Recipe')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

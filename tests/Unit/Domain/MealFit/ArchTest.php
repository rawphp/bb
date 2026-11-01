<?php

arch('app/Domain/MealFit is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\MealFit')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

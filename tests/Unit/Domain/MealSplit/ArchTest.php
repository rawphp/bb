<?php

arch('app/Domain/MealSplit is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\MealSplit')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

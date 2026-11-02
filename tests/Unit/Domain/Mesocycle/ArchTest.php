<?php

arch('app/Domain/Mesocycle is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Mesocycle')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

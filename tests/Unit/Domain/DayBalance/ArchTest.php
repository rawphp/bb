<?php

arch('app/Domain/DayBalance is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\DayBalance')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

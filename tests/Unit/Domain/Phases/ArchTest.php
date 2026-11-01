<?php

arch('app/Domain/Phases is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Phases')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

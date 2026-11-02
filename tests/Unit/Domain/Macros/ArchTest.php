<?php

arch('app/Domain/Macros is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Macros')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

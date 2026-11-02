<?php

arch('app/Domain/Foods is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Foods')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

<?php

arch('app/Domain/Safety is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Safety')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

<?php

arch('app/Domain/Defaults is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Defaults')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

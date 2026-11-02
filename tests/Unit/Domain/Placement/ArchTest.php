<?php

arch('app/Domain/Placement is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Placement')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

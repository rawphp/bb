<?php

arch('app/Domain/Progression is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Progression')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

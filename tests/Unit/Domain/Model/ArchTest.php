<?php

arch('app/Domain/Model is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Model')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

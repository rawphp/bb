<?php

arch('app/Domain/Energy is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Energy')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'config',
    ]);

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
        'time',
        'date',
        'microtime',
        'hrtime',
    ]);

it('app/Domain/Safety never reads the clock through a DateTime constructor', function () {
    $readsClock = '/new\s+\\\\?DateTime(Immutable)?\s*\(\s*(\)|[\'"]now[\'"])/i';

    $files = glob(dirname(__DIR__, 4).'/app/Domain/Safety/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(preg_match($readsClock, (string) file_get_contents($file)))->toBe(0, basename($file));
    }
});

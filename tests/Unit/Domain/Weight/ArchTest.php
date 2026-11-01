<?php

arch('app/Domain/Weight is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Weight')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'today',
        'config',
        // Every PHP function that reads the clock when it is not handed a timestamp.
        'time',
        'microtime',
        'hrtime',
        'date',
        'gmdate',
        'idate',
        'getdate',
        'localtime',
        'mktime',
        'gmmktime',
        'strtotime',
        'strftime',
        'gmstrftime',
        'date_create',
        'date_create_immutable',
    ]);

it('app/Domain/Weight builds no dates: it only reads the ones it is given', function () {
    // Any constructor or static factory of DateTime, DateTimeImmutable or DatePeriod, whatever its
    // argument or none ('new DateTimeImmutable' needs no parentheses): 'today', '' and a partial
    // format all fill in from the clock.
    $buildsDate = '/new\s+\\\\?Date(Time|Period)\w*|\bDate(Time|Period)\w*::/i';

    $files = glob(dirname(__DIR__, 4).'/app/Domain/Weight/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(preg_match($buildsDate, (string) file_get_contents($file)))->toBe(0, basename($file));
    }
});

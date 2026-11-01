<?php

arch('app/Domain/Phases is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Phases')
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

it('app/Domain/Phases builds no dates: it only moves the ones it is given', function () {
    // Any constructor or static factory of DateTime, DateTimeImmutable or DatePeriod, with or without
    // parentheses: 'today', '' and a partial format all fill in from the clock. DateTimeZone is allowed.
    $buildsDate = '/new\s+\\\\?Date(Time(Immutable)?|Period)\b|\bDate(Time(Immutable)?|Period)::/i';

    $files = glob(dirname(__DIR__, 4).'/app/Domain/Phases/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(preg_match($buildsDate, (string) file_get_contents($file)))->toBe(0, basename($file));
    }
});

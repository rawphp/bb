<?php

use App\Domain\Scan\ScanDescription;

it('FOOD-044 a description is trimmed before it is sent', function () {
    expect(ScanDescription::text("  chicken burrito bowl with rice \n"))->toBe('chicken burrito bowl with rice');
});

it('FOOD-044 a description of one to a thousand characters can be sent, spaces around it not counted', function (string $text) {
    expect(ScanDescription::text($text))->toBe(trim($text));
})->with([
    'one character' => ['x'],
    'a thousand' => [str_repeat('x', 1000)],
    'a thousand with spaces around' => [' '.str_repeat('x', 1000).' '],
    'a thousand accented' => [str_repeat('é', 1000)],
]);

it('FOOD-044 a description outside the limits is refused with the rule in plain words', function (string $text) {
    expect(fn () => ScanDescription::text($text))->toThrow(DomainException::class, 'Describe the meal in 1 to 1000 characters.');
})->with([
    'empty' => [''],
    'only spaces' => ["  \n "],
    'a thousand and one' => [str_repeat('x', 1001)],
]);

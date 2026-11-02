<?php

use App\Domain\Foods\Barcode;

it('FOOD-041 normalizes UPC-12 and EAN-13 of one product to the same key', function () {
    expect(Barcode::normalize('012345678905'))->toBe('12345678905')
        ->and(Barcode::normalize('0012345678905'))->toBe('12345678905')
        ->and(Barcode::normalize(' 3017620422003 '))->toBe('3017620422003');
});

it('FOOD-041 rejects codes that are not digits', function (?string $code) {
    expect(Barcode::normalize($code))->toBeNull();
})->with([null, '', '   ', 'abc', '12-34', '0000', '12 34']);

it('FOOD-041 rejects codes longer than 14 digits once leading zeros are stripped', function () {
    expect(Barcode::normalize(str_repeat('1', 40)))->toBeNull()
        ->and(Barcode::normalize('123456789012345'))->toBeNull()
        ->and(Barcode::normalize('12345678901234'))->toBe('12345678901234')
        ->and(Barcode::normalize('00012345678901234'))->toBe('12345678901234');
});

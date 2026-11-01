<?php

use App\Domain\Model\ClosedRange;

it('TBL-010 a rate range holds both ends and what lies between, and nothing past them', function (float $value, bool $inside) {
    expect((new ClosedRange(-1.0, -0.25))->contains($value))->toBe($inside);
})->with([
    'lower end' => [-1.0, true],
    'upper end' => [-0.25, true],
    'between' => [-0.5, true],
    'below' => [-1.01, false],
    'above' => [-0.24, false],
]);

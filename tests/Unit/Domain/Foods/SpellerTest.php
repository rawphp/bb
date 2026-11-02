<?php

use App\Domain\Foods\Speller;

it('FOOD-024 collects lowercase words of three or more letters from food names, keeping repeats', function () {
    expect(Speller::words(['Chicken breast, raw', 'Egg', 'Rice (white)', 'Oz', 'Chicken thigh']))
        ->toBe(['chicken', 'breast', 'raw', 'egg', 'rice', 'white', 'chicken', 'thigh']);
});

it('FOOD-024 corrects misspelled terms to the nearest vocabulary word', function (string $query, string $expected) {
    $speller = new Speller(['chicken', 'breast', 'rice', 'egg', 'salmon', 'broccoli']);

    expect($speller->correct($query))->toBe($expected);
})->with([
    'two typos' => ['chiken brest', 'chicken breast'],
    'case is ignored' => ['Chiken BREAST', 'chicken breast'],
    'one letter off on a short word' => ['egs', 'egg'],
    'known words stay' => ['chicken rice', 'chicken rice'],
    'only the wrong term changes' => ['salmon brocoli', 'salmon broccoli'],
    'two edits allowed on long words' => ['brocolli', 'broccoli'],
]);

it('FOOD-024 leaves terms alone when nothing is close enough', function (string $query) {
    $speller = new Speller(['chicken', 'breast', 'rice', 'egg']);

    expect($speller->correct($query))->toBe($query);
})->with([
    'unrelated' => ['zzzzzz'],
    'short word two edits away' => ['rcie'],
    'long word three edits away' => ['chxxxen'],
]);

it('FOOD-024 breaks ties by frequency, then alphabetically', function () {
    expect((new Speller(['rice', 'rick', 'rick']))->correct('rico'))->toBe('rick')
        ->and((new Speller(['rick', 'rice']))->correct('rico'))->toBe('rice');
});

it('FOOD-024 returns an empty phrase for an empty query', function () {
    expect((new Speller(['rice']))->correct('  '))->toBe('');
});

it('FOOD-024 builds its vocabulary from food names', function () {
    expect(Speller::fromNames(['Chicken breast', 'Chicken thigh'])->correct('chiken thig'))->toBe('chicken thigh');
});

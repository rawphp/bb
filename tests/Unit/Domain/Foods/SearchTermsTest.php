<?php

use App\Domain\Foods\SearchTerms;

it('FOOD-029 splits a query into lowercase terms on non-alphanumerics', function (string $query, array $terms) {
    expect(SearchTerms::of($query))->toBe($terms);
})->with([
    ['Chicken, Breast!', ['chicken', 'breast']],
    ['  rice   ', ['rice']],
    ['ben & jerry\'s', ['ben', 'jerry', 's']],
    ['Crème fraîche', ['crème', 'fraîche']],
    ['%_;--', []],
]);

it('FOOD-029 builds a boolean full-text query from terms of three letters or more', function (array $terms, ?string $expected) {
    expect(SearchTerms::booleanQuery($terms))->toBe($expected);
})->with([
    [['chicken', 'breast'], '+chicken* +breast*'],
    [['chicken', 'br'], '+chicken*'],
    [['br', 'c'], null],
    [[], null],
]);

it('FOOD-029 drops InnoDB stopwords from the boolean full-text query', function (array $terms, ?string $expected) {
    expect(SearchTerms::booleanQuery($terms))->toBe($expected);
})->with([
    [['the', 'chicken'], '+chicken*'],
    [['the'], null],
    [['with', 'the'], null],
    [['chicken', 'with', 'rice'], '+chicken* +rice*'],
]);

it('FOOD-023 keeps stopwords for LIKE matching only when nothing else is left', function (array $terms, array $expected) {
    expect(SearchTerms::meaningful($terms))->toBe($expected);
})->with([
    [['the', 'chicken'], ['chicken']],
    [['the', 'with'], ['the', 'with']],
    [[], []],
]);

<?php

use App\Domain\Model\DietFilter;

it('FOOD-027 passes a mix of foods through a diet filter only when every food passes it', function () {
    $egg = ['dairy_free' => true, 'gluten_free' => true, 'grain_free' => true, 'low_fodmap' => true, 'vegetarian' => true, 'paleo' => true, 'vegan' => false];
    $oats = ['dairy_free' => true, 'gluten_free' => false, 'grain_free' => false, 'low_fodmap' => true, 'vegetarian' => true, 'paleo' => false, 'vegan' => true];

    expect(DietFilter::sharedBy([$egg, $oats]))->toBe([
        'dairy_free' => true,
        'gluten_free' => false,
        'grain_free' => false,
        'low_fodmap' => true,
        'vegetarian' => true,
        'paleo' => false,
        'vegan' => false,
    ])->and(DietFilter::sharedBy([$egg]))->toBe($egg);
});

it('FOOD-027 treats a flag a food does not name as not passing', function () {
    expect(DietFilter::sharedBy([['vegan' => true]]))->toBe([
        'dairy_free' => false,
        'gluten_free' => false,
        'grain_free' => false,
        'low_fodmap' => false,
        'vegetarian' => false,
        'paleo' => false,
        'vegan' => true,
    ]);
});

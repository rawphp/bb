<?php

use App\Domain\Model\ConsumedTotals;
use App\Domain\Model\DietFilter;
use App\Domain\Model\Priority;

it('MESO-030 shows three bars for emphasize, two for grow and one for maintain', function () {
    expect(array_map(fn (Priority $priority) => $priority->bars(), Priority::cases()))->toBe([3, 2, 1]);
});

it('SET-003 names both consumed-totals choices', function () {
    expect(ConsumedTotals::Planned->value)->toBe('planned')
        ->and(ConsumedTotals::Planned->title())->toBe('Include planned meals')
        ->and(ConsumedTotals::CheckedIn->value)->toBe('checked_in')
        ->and(ConsumedTotals::CheckedIn->title())->toBe('Checked-in meals only');
});

it('FOOD-027 names the seven diet filters with the server values', function () {
    expect(array_map(fn (DietFilter $filter) => [$filter->value, $filter->title()], DietFilter::cases()))->toBe([
        ['dairy_free', 'Dairy free'],
        ['gluten_free', 'Gluten free'],
        ['grain_free', 'Grain free'],
        ['low_fodmap', 'Low FODMAP'],
        ['vegetarian', 'Ovo-lacto vegetarian'],
        ['paleo', 'Paleo'],
        ['vegan', 'Vegan'],
    ]);
});

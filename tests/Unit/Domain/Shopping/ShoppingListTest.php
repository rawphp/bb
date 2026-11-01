<?php

use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\FoodAmount;
use App\Domain\Shopping\PlannedFoodDay;
use App\Domain\Shopping\PlannedItem;
use App\Domain\Shopping\Recipe;
use App\Domain\Shopping\ShoppingFood;
use App\Domain\Shopping\ShoppingGroup;
use App\Domain\Shopping\ShoppingItem;
use App\Domain\Shopping\ShoppingList;
use App\Domain\Shopping\StoreCategory;
use App\Domain\Shopping\Weighed;

function shoppingDay(int $offset, int $hour = 12): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-03-01 00:00', new DateTimeZone('UTC')))
        ->modify("{$offset} days")
        ->setTime($hour, 0);
}

function shoppingFood(string $id): ShoppingFood
{
    return match ($id) {
        'chicken' => new ShoppingFood('chicken', 'Chicken breast', StoreCategory::Protein, cookedRatio: 0.75),
        'rice' => new ShoppingFood('rice', 'White rice', StoreCategory::GrainsAndBread, cookedRatio: 3.0),
        'broccoli' => new ShoppingFood('broccoli', 'Broccoli', StoreCategory::Produce),
        'apple' => new ShoppingFood('apple', 'Apple', StoreCategory::Produce),
        'yogurt' => new ShoppingFood('yogurt', 'Greek yogurt', StoreCategory::Dairy),
        'oil' => new ShoppingFood('oil', 'Olive oil', StoreCategory::Pantry),
        'peas' => new ShoppingFood('peas', 'Frozen peas', StoreCategory::Frozen),
        'whey' => new ShoppingFood('whey', 'Whey protein', StoreCategory::Other),
    };
}

function plannedFood(string $id, float $grams, Weighed $weighed = Weighed::Raw): PlannedItem
{
    return PlannedItem::food(new FoodAmount(shoppingFood($id), $grams, $weighed));
}

/**
 * @param  list<PlannedFoodDay>  $planned
 * @return list<ShoppingGroup>
 */
function shoppingList(array $planned, ?DateRange $days = null): array
{
    return ShoppingList::build($planned, $days ?? new DateRange(shoppingDay(0), shoppingDay(6)), new DateTimeZone('UTC'));
}

/**
 * @param  list<ShoppingGroup>  $groups
 * @return array<string, float>
 */
function shoppingGrams(array $groups): array
{
    $grams = [];
    foreach ($groups as $group) {
        foreach ($group->items as $item) {
            $grams[$item->food->id] = $item->grams;
        }
    }

    return $grams;
}

// SHOP-001

it('SHOP-001 builds from planned foods in the chosen date range', function () {
    $planned = [
        new PlannedFoodDay(shoppingDay(-1), [plannedFood('apple', 150)]),
        new PlannedFoodDay(shoppingDay(0, hour: 0), [plannedFood('broccoli', 100)]),
        new PlannedFoodDay(shoppingDay(2), [plannedFood('yogurt', 200)]),
        new PlannedFoodDay(shoppingDay(3, hour: 23), [plannedFood('oil', 10)]),
        new PlannedFoodDay(shoppingDay(4), [plannedFood('peas', 80)]),
    ];

    // Only the planned days from day 0 to day 3 count, whatever the time of day.
    expect(shoppingGrams(shoppingList($planned, new DateRange(shoppingDay(0, hour: 18), shoppingDay(3, hour: 6)))))
        ->toEqual(['broccoli' => 100, 'yogurt' => 200, 'oil' => 10])
        ->and(shoppingList([]))->toBe([]);
});

it('SHOP-001 the default range is the next 7 days starting today', function () {
    $range = ShoppingList::defaultRange(shoppingDay(0, hour: 15), new DateTimeZone('UTC'));

    expect(ShoppingList::DEFAULT_DAYS)->toBe(7)
        ->and($range->lower)->toEqual(shoppingDay(0, hour: 0))
        ->and($range->upper)->toEqual(shoppingDay(6, hour: 0));

    $planned = array_map(fn (int $offset) => new PlannedFoodDay(shoppingDay($offset), [plannedFood('apple', 100)]), range(0, 7));
    expect(shoppingGrams(shoppingList($planned, $range)))->toEqual(['apple' => 700]);
});

// SHOP-002

it('SHOP-002 breaks recipes into their ingredients scaled to the servings planned', function () {
    $stirFry = new Recipe('Chicken stir fry', 4, [
        new FoodAmount(shoppingFood('chicken'), 600),
        new FoodAmount(shoppingFood('broccoli'), 400),
        new FoodAmount(shoppingFood('oil'), 20),
    ]);
    $planned = [
        new PlannedFoodDay(shoppingDay(0), [PlannedItem::recipe($stirFry, servings: 1)]),
        new PlannedFoodDay(shoppingDay(1), [PlannedItem::recipe($stirFry, servings: 1.5), plannedFood('broccoli', 50)]),
    ];

    // 2.5 of 4 servings: 375 g chicken, 250 g broccoli (+50 g on its own), 12.5 g oil.
    $groups = shoppingList($planned);
    $names = [];
    foreach ($groups as $group) {
        foreach ($group->items as $item) {
            $names[] = $item->food->name;
        }
    }

    expect(shoppingGrams($groups))->toEqual(['chicken' => 400, 'broccoli' => 300, 'oil' => 20])
        ->and($names)->not->toContain('Chicken stir fry');
});

it('SHOP-002 a recipe with no servings adds nothing', function () {
    $broken = new Recipe('Broken', 0, [new FoodAmount(shoppingFood('chicken'), 600)]);

    expect(shoppingList([new PlannedFoodDay(shoppingDay(0), [PlannedItem::recipe($broken, servings: 1)])]))->toBe([]);
});

// SHOP-003

it('SHOP-003 groups by store category in store order', function () {
    $planned = [new PlannedFoodDay(shoppingDay(0), array_map(
        fn (string $id) => plannedFood($id, 100),
        ['whey', 'peas', 'oil', 'yogurt', 'rice', 'broccoli', 'apple', 'chicken'],
    ))];

    expect(StoreCategory::cases())->toBe([
        StoreCategory::Protein, StoreCategory::Produce, StoreCategory::GrainsAndBread, StoreCategory::Dairy,
        StoreCategory::Pantry, StoreCategory::Frozen, StoreCategory::Other,
    ])
        ->and(array_map(fn (ShoppingGroup $group) => $group->category, shoppingList($planned)))->toBe(StoreCategory::cases());

    // Within a group, by name.
    $produce = array_map(
        fn (string $name) => PlannedItem::food(new FoodAmount(new ShoppingFood($name, $name, StoreCategory::Produce), 100)),
        ['Zucchini', 'Kale', 'Banana', 'Spinach', 'Apple', 'Onion', 'Carrot'],
    );
    $byName = shoppingList([new PlannedFoodDay(shoppingDay(0), $produce)]);
    expect(array_map(fn (ShoppingItem $item) => $item->food->name, $byName[0]->items))
        ->toBe(['Apple', 'Banana', 'Carrot', 'Kale', 'Onion', 'Spinach', 'Zucchini']);

    // Empty categories are left out.
    expect(array_map(
        fn (ShoppingGroup $group) => $group->category,
        shoppingList([new PlannedFoodDay(shoppingDay(0), [plannedFood('yogurt', 100)])]),
    ))->toBe([StoreCategory::Dairy]);
});

it('SHOP-003 sums each food across days before rounding', function () {
    $planned = array_map(fn (int $offset) => new PlannedFoodDay(shoppingDay($offset), [plannedFood('yogurt', 170)]), range(0, 4));

    // 850 g, not five times 170 rounded up to 200.
    expect(shoppingList($planned))->toEqual([
        new ShoppingGroup(StoreCategory::Dairy, [new ShoppingItem(shoppingFood('yogurt'), 850)]),
    ]);
});

it('SHOP-003 rounds up to the next friendly purchase amount', function () {
    // Up to 100 g in 10 g steps, up to 1 kg in 50 g steps, then 100 g steps.
    expect(ShoppingList::purchaseGrams(0.4))->toEqual(10)
        ->and(ShoppingList::purchaseGrams(12))->toEqual(20)
        ->and(ShoppingList::purchaseGrams(100))->toEqual(100)
        ->and(ShoppingList::purchaseGrams(100.5))->toEqual(150)
        ->and(ShoppingList::purchaseGrams(410))->toEqual(450)
        ->and(ShoppingList::purchaseGrams(1000))->toEqual(1000)
        ->and(ShoppingList::purchaseGrams(1001))->toEqual(1100)
        ->and(ShoppingList::purchaseGrams(2350))->toEqual(2400)
        ->and(ShoppingList::purchaseGrams(0))->toEqual(0)
        // Floating-point noise does not push a whole amount up a step.
        ->and(ShoppingList::purchaseGrams(21.0 / 0.7))->toEqual(30)
        ->and(ShoppingList::purchaseGrams(210.0 / 0.7))->toEqual(300);
});

it('SHOP-003 leaves out foods with nothing to buy', function () {
    $planned = [new PlannedFoodDay(shoppingDay(0), [plannedFood('apple', 0), plannedFood('yogurt', 100)])];

    expect(shoppingGrams(shoppingList($planned)))->toEqual(['yogurt' => 100]);
});

// SHOP-004

it('SHOP-004 converts cooked amounts to the raw weight to buy', function () {
    expect((new FoodAmount(shoppingFood('chicken'), 150, Weighed::Cooked))->rawGrams())->toEqualWithDelta(200, 1e-9)
        ->and((new FoodAmount(shoppingFood('rice'), 300, Weighed::Cooked))->rawGrams())->toEqualWithDelta(100, 1e-9)
        ->and((new FoodAmount(shoppingFood('chicken'), 150, Weighed::Raw))->rawGrams())->toEqual(150)
        // A food that does not change weight when cooked is bought as weighed.
        ->and((new FoodAmount(shoppingFood('broccoli'), 150, Weighed::Cooked))->rawGrams())->toEqual(150);

    $planned = [
        new PlannedFoodDay(shoppingDay(0), [plannedFood('chicken', 225, Weighed::Cooked), plannedFood('rice', 450, Weighed::Cooked)]),
        new PlannedFoodDay(shoppingDay(1), [plannedFood('chicken', 200, Weighed::Raw), plannedFood('rice', 60, Weighed::Raw)]),
    ];

    // Chicken 300 + 200 raw; rice 150 + 60 raw = 210, bought as 250.
    expect(shoppingGrams(shoppingList($planned)))->toEqual(['chicken' => 500, 'rice' => 250]);
});

it('SHOP-004 converts cooked recipe ingredients too', function () {
    $bowl = new Recipe('Rice bowl', 2, [new FoodAmount(shoppingFood('rice'), 600, Weighed::Cooked)]);

    expect(shoppingGrams(shoppingList([new PlannedFoodDay(shoppingDay(0), [PlannedItem::recipe($bowl, servings: 1)])])))
        ->toEqual(['rice' => 100]);
});

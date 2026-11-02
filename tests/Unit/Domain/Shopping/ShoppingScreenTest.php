<?php

use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\ListSection;
use App\Domain\Shopping\ManualItem;
use App\Domain\Shopping\ShoppingFood;
use App\Domain\Shopping\ShoppingGroup;
use App\Domain\Shopping\ShoppingItem;
use App\Domain\Shopping\ShoppingScreen;
use App\Domain\Shopping\StoreCategory;

// The list as the screen shows it (rp-clone ShoppingListModel: sections, shareText).

/** @return list<ShoppingGroup> one group per category given, each item [id, name, grams] */
function screenGroups(array $byCategory): array
{
    $groups = [];
    foreach ($byCategory as $category => $items) {
        $groups[] = new ShoppingGroup(StoreCategory::from($category), array_map(
            fn (array $item) => new ShoppingItem(new ShoppingFood($item[0], $item[1], StoreCategory::from($category)), $item[2]),
            $items,
        ));
    }

    return $groups;
}

/**
 * @param  list<ListSection>  $sections
 * @return array<string, list<string>> title => "name: amount" lines, "-" for no amount
 */
function screenLines(array $sections): array
{
    $lines = [];
    foreach ($sections as $section) {
        $lines[$section->category->title()] = array_map(fn ($line) => "{$line->name}: ".($line->amountText ?? '-'), $section->lines);
    }

    return $lines;
}

it('SHOP-003 keeps the groups in store order, each line keyed by its food and showing whole grams', function () {
    $sections = ShoppingScreen::sections(screenGroups([
        'protein' => [['chicken', 'Chicken breast', 2100.0]],
        'grainsAndBread' => [['rice', 'White rice', 1100.0]],
    ]), [], []);

    expect(screenLines($sections))->toBe([
        'Protein' => ['Chicken breast: 2100 g'],
        'Grains and bread' => ['White rice: 1100 g'],
    ])
        ->and($sections[0]->lines[0]->key)->toBe('food:chicken')
        ->and($sections[0]->lines[0]->isManual)->toBeFalse()
        ->and($sections[0]->lines[0]->isTicked)->toBeFalse();
});

it('SHOP-003 shows a purchase amount as whole grams', function (float $grams, string $text) {
    expect(ShoppingScreen::amountText($grams))->toBe($text);
})->with([
    [1100.0, '1100 g'],
    [60.0, '60 g'],
    [299.99999999999994, '300 g'],
]);

it('SHOP-005 lists a manual item under Other, after the planned ones', function () {
    $sections = ShoppingScreen::sections(
        screenGroups(['other' => [['gum', 'Xanthan gum', 10.0]]]),
        [new ManualItem('01JBX5H0000000000000000001', 'Birthday candles')],
        [],
    );

    expect(screenLines($sections))->toBe(['Other' => ['Xanthan gum: 10 g', 'Birthday candles: -']])
        ->and(array_map(fn ($line) => $line->isManual, $sections[0]->lines))->toBe([false, true])
        ->and($sections[0]->lines[1]->key)->toBe('manual:01JBX5H0000000000000000001');
});

it('SHOP-005 makes the Other group for a manual item alone, last in store order', function () {
    $sections = ShoppingScreen::sections(
        screenGroups(['produce' => [['broccoli', 'Broccoli', 200.0]]]),
        [new ManualItem('01JBX5H0000000000000000001', 'Paper towels'), new ManualItem('01JBX5H0000000000000000002', 'Candles')],
        [],
    );

    expect(screenLines($sections))->toBe(['Produce' => ['Broccoli: 200 g'], 'Other' => ['Paper towels: -', 'Candles: -']]);
});

it('SHOP-005 shows the ticked lines, planned and manual, as ticked', function () {
    $sections = ShoppingScreen::sections(
        screenGroups(['produce' => [['broccoli', 'Broccoli', 200.0], ['spinach', 'Spinach', 100.0]]]),
        [new ManualItem('01JBX5H0000000000000000001', 'Dish soap')],
        ['food:spinach', 'manual:01JBX5H0000000000000000001'],
    );

    expect(array_map(fn ($line) => $line->isTicked, $sections[0]->lines))->toBe([false, true])
        ->and($sections[1]->lines[0]->isTicked)->toBeTrue();
});

it('SHOP-005 shares the list as text: a heading per group and a line per item with its grams', function () {
    $sections = ShoppingScreen::sections(
        screenGroups([
            'protein' => [['chicken', 'Chicken breast', 400.0]],
            'produce' => [['broccoli', 'Broccoli', 200.0]],
            'dairy' => [['yogurt', 'Greek yogurt', 250.0]],
        ]),
        [new ManualItem('01JBX5H0000000000000000001', 'Birthday candles')],
        [],
    );

    expect(ShoppingScreen::shareText($sections))->toBe(
        "Protein\nChicken breast — 400 g\n\nProduce\nBroccoli — 200 g\n\nDairy\nGreek yogurt — 250 g\n\nOther\nBirthday candles"
    );
});

it('SHOP-005 leaves ticked items and the groups they empty out of the shared text', function () {
    $sections = ShoppingScreen::sections(
        screenGroups(['protein' => [['chicken', 'Chicken breast', 300.0]], 'produce' => [['broccoli', 'Broccoli', 200.0]]]),
        [new ManualItem('01JBX5H0000000000000000001', 'Candles'), new ManualItem('01JBX5H0000000000000000002', 'Soap')],
        ['food:chicken', 'manual:01JBX5H0000000000000000001'],
    );

    expect(ShoppingScreen::shareText($sections))->toBe("Produce\nBroccoli — 200 g\n\nOther\nSoap");
});

it('SHOP-005 shares nothing when nothing is planned or everything is ticked', function () {
    $manual = [new ManualItem('01JBX5H0000000000000000001', 'Soap')];

    expect(ShoppingScreen::shareText(ShoppingScreen::sections([], [], [])))->toBe('')
        ->and(ShoppingScreen::shareText(ShoppingScreen::sections([], $manual, ['manual:01JBX5H0000000000000000001'])))->toBe('');
});

it('SHOP-001 names the range by its first and last day', function () {
    $utc = new DateTimeZone('UTC');

    expect(ShoppingScreen::rangeText(new DateRange(new DateTimeImmutable('2026-11-02', $utc), new DateTimeImmutable('2026-11-08', $utc))))
        ->toBe('Nov 2 – Nov 8')
        ->and(ShoppingScreen::rangeText(new DateRange(new DateTimeImmutable('2026-12-29', $utc), new DateTimeImmutable('2027-01-04', $utc))))
        ->toBe('Dec 29 – Jan 4')
        ->and(ShoppingScreen::rangeText(new DateRange(new DateTimeImmutable('2026-11-02', $utc), new DateTimeImmutable('2026-11-02', $utc))))
        ->toBe('Nov 2');
});

it('SHOP-001 says so when nothing is on the list', function () {
    expect(ShoppingScreen::emptyText([]))->toBe('No foods planned for these days.')
        ->and(ShoppingScreen::emptyText(ShoppingScreen::sections([], [new ManualItem('01JBX5H0000000000000000001', 'Soap')], [])))->toBeNull();
});

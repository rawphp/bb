<?php

use App\Domain\Shopping\StoreCategory;

/** SHOP-003: which store category a food belongs to. Name keywords first, then the curated group, else other. */
function storeCategory(string $name, ?string $curatedGroup = null): StoreCategory
{
    return StoreCategory::of($name, $curatedGroup);
}

it('SHOP-003 categories have the titles the list shows', function () {
    expect(array_map(fn (StoreCategory $category) => $category->title(), StoreCategory::cases()))->toBe([
        'Protein', 'Produce', 'Grains and bread', 'Dairy', 'Pantry', 'Frozen', 'Other',
    ]);
});

it('SHOP-003 yogurt, milk and cheese are dairy by name', function () {
    expect(storeCategory('Yogurt'))->toBe(StoreCategory::Dairy)
        ->and(storeCategory('Whole milk'))->toBe(StoreCategory::Dairy)
        ->and(storeCategory('Cheddar cheese'))->toBe(StoreCategory::Dairy)
        ->and(storeCategory('Butter'))->toBe(StoreCategory::Dairy)
        ->and(storeCategory('Cottage cheese'))->toBe(StoreCategory::Dairy);
});

it('SHOP-003 the name beats the curated group', function () {
    expect(storeCategory('Greek yogurt, nonfat', 'protein_recommended'))->toBe(StoreCategory::Dairy)
        ->and(storeCategory('Chicken breast', 'carbs_sparingly'))->toBe(StoreCategory::Protein);
});

it('SHOP-003 matching ignores case and punctuation', function () {
    expect(storeCategory('GREEK YOGURT, nonfat'))->toBe(StoreCategory::Dairy)
        ->and(storeCategory('eggs (large)'))->toBe(StoreCategory::Protein);
});

it('SHOP-003 butter beans are pantry', function () {
    expect(storeCategory('Butter beans'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Butter bean'))->toBe(StoreCategory::Pantry);
});

it('SHOP-003 a specific phrase beats a word in it', function () {
    expect(storeCategory('Peanut butter'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Frozen peas'))->toBe(StoreCategory::Frozen)
        ->and(storeCategory('Ice cream'))->toBe(StoreCategory::Frozen)
        ->and(storeCategory('Chicken stock'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Tomato sauce'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Green beans'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Black beans'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Black pepper'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Egg noodles'))->toBe(StoreCategory::GrainsAndBread);
});

it('SHOP-003 a keyword matches whole words only', function () {
    // "Peanuts" holds "pea" but is not peas.
    expect(storeCategory('Peanuts'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Pineapple'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Butternut squash'))->toBe(StoreCategory::Produce);
});

it('SHOP-003 a plural matches its keyword', function () {
    expect(storeCategory('Eggs'))->toBe(StoreCategory::Protein)
        ->and(storeCategory('Bananas'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Tomatoes'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Rolled oats'))->toBe(StoreCategory::GrainsAndBread);
});

it('SHOP-003 every category has name keywords', function () {
    expect(storeCategory('Salmon fillet'))->toBe(StoreCategory::Protein)
        ->and(storeCategory('Tofu'))->toBe(StoreCategory::Protein)
        ->and(storeCategory('Broccoli'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Blueberries'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Sweet potato'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Basmati rice'))->toBe(StoreCategory::GrainsAndBread)
        ->and(storeCategory('Wholemeal bread'))->toBe(StoreCategory::GrainsAndBread)
        ->and(storeCategory('Pasta'))->toBe(StoreCategory::GrainsAndBread)
        ->and(storeCategory('Olive oil'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Almonds'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Honey'))->toBe(StoreCategory::Pantry);
});

it('SHOP-003 a name with no keyword uses the curated group', function () {
    expect(storeCategory('Whey protein', 'protein_recommended'))->toBe(StoreCategory::Protein)
        ->and(storeCategory('Mystery meat', 'protein_sparingly'))->toBe(StoreCategory::Protein)
        ->and(storeCategory('Bedtime shake', 'protein_before_bed'))->toBe(StoreCategory::Protein)
        ->and(storeCategory('Garden mix', 'veggies'))->toBe(StoreCategory::Produce)
        ->and(storeCategory('Mystery grain', 'carbs_recommended'))->toBe(StoreCategory::GrainsAndBread)
        ->and(storeCategory('Mystery grain', 'carbs_sparingly'))->toBe(StoreCategory::GrainsAndBread)
        ->and(storeCategory('Mystery spread', 'fat_recommended'))->toBe(StoreCategory::Pantry)
        ->and(storeCategory('Mystery spread', 'fat_sparingly'))->toBe(StoreCategory::Pantry);
});

it('SHOP-003 a name with no keyword and no group is other', function () {
    expect(storeCategory('Xanthan gum'))->toBe(StoreCategory::Other)
        ->and(storeCategory(''))->toBe(StoreCategory::Other);
});

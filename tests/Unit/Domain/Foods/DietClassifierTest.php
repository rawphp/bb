<?php

use App\Domain\Foods\DietClassifier;

const FOODS_ALL_DIETS_FALSE = [
    'dairy_free' => false, 'gluten_free' => false, 'grain_free' => false, 'low_fodmap' => false,
    'vegetarian' => false, 'paleo' => false, 'vegan' => false,
];

function foodsDiets(array $flags): array
{
    return array_merge(FOODS_ALL_DIETS_FALSE, $flags);
}

it('FOOD-027 classifies generic foods from name and category alone', function (string $name, ?string $category, array $true) {
    $flags = (new DietClassifier)->classify($name, $category, null, [], generic: true);

    expect($flags)->toBe(foodsDiets(array_fill_keys($true, true)));
})->with([
    'chicken breast' => ['Chicken, broilers or fryers, breast, raw', 'Poultry Products', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'paleo']],
    'whole milk' => ['Milk, whole', 'Dairy and Egg Products', ['gluten_free', 'grain_free', 'vegetarian']],
    'white rice' => ['Rice, white, raw', 'Cereal Grains and Pasta', ['dairy_free', 'gluten_free', 'low_fodmap', 'vegetarian', 'vegan']],
    'wheat bread' => ['Bread, wheat', 'Baked Products', []],
    'eggs' => ['Egg, whole, raw', 'Dairy and Egg Products', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo']],
    'honey' => ['Honey', 'Sweets', []],
    'garlic' => ['Garlic, raw', 'Vegetables and Vegetable Products', ['dairy_free', 'gluten_free', 'grain_free', 'vegetarian', 'paleo', 'vegan']],
    'banana' => ['Bananas, raw', 'Fruits and Fruit Juices', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan']],
    'lentils' => ['Lentils, raw', 'Legumes and Legume Products', ['dairy_free', 'gluten_free', 'grain_free', 'vegetarian', 'vegan']],
    'salmon' => ['Fish, salmon, Atlantic, raw', 'Finfish and Shellfish Products', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'paleo']],
    'almond milk' => ['Almond milk, unsweetened', 'Beverages', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan']],
    'peanut butter' => ['Peanut butter, smooth', 'Legumes and Legume Products', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'vegan']],
    'butter' => ['Butter, salted', 'Dairy and Egg Products', ['gluten_free', 'grain_free', 'vegetarian']],
    'bagels' => ['Bagels, plain', 'Baked Products', []],
    'broccoli' => ['Broccoli, raw', 'Vegetables and Vegetable Products', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan']],
    'chicken breast raw' => ['Chicken, broiler, breast, raw', 'Poultry Products', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'paleo']],
    'brie' => ['Cheese, brie', 'Dairy and Egg Products', ['gluten_free', 'grain_free', 'vegetarian']],
    'oat bran' => ['Oat bran, raw', 'Cereal Grains and Pasta', ['dairy_free', 'vegetarian', 'vegan']],
    'spaghetti' => ['Spaghetti, dry', 'Cereal Grains and Pasta', ['dairy_free', 'vegetarian', 'vegan']],
    'quinoa' => ['Quinoa, uncooked', 'Cereal Grains and Pasta', ['dairy_free', 'gluten_free', 'low_fodmap', 'vegetarian', 'vegan']],
    'sausage' => ['Sausage, pork, raw', 'Sausages and Luncheon Meats', []],
    'beer' => ['Alcoholic beverage, beer, regular', 'Beverages', ['dairy_free', 'vegetarian', 'vegan']],
    'snack' => ['Potato chips, plain', 'Snacks', []],
    'no category' => ['Broccoli, raw', null, []],
    'empty category' => ['Broccoli, raw', '', []],
]);

it('FOOD-027 classifies packaged foods from ingredients', function (string $ingredients, array $true) {
    $flags = (new DietClassifier)->classify('Some product', null, $ingredients, []);

    expect($flags)->toBe(foodsDiets(array_fill_keys($true, true)));
})->with([
    'rice flour is gluten free but a grain' => ['rice flour, water, salt', ['dairy_free', 'gluten_free', 'low_fodmap', 'vegetarian', 'vegan']],
    'almond flour is grain free' => ['almond flour, eggs, honey', ['dairy_free', 'gluten_free', 'grain_free', 'vegetarian', 'paleo']],
    'wheat flour' => ['wheat flour, water, yeast', ['dairy_free', 'vegetarian', 'vegan']],
    'sugar and milk' => ['sugar, skimmed milk powder, cocoa', ['gluten_free', 'grain_free', 'vegetarian']],
    'gelatin is not vegetarian' => ['water, gelatin, citric acid', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'paleo']],
    'onion is high FODMAP' => ['tomato, onion, salt', ['dairy_free', 'gluten_free', 'grain_free', 'vegetarian', 'paleo', 'vegan']],
    'sugar alcohol is high FODMAP' => ['water, xylitol, flavour', ['dairy_free', 'gluten_free', 'grain_free', 'vegetarian', 'vegan']],
]);

it('FOOD-027 sets every flag false for a packaged product with no ingredients and no tags', function () {
    expect((new DietClassifier)->classify('Granola bar', 'Snacks', null, []))->toBe(FOODS_ALL_DIETS_FALSE)
        ->and((new DietClassifier)->classify('Granola bar', 'Snacks', '  ', []))->toBe(FOODS_ALL_DIETS_FALSE);
});

it('FOOD-027 lets Open Food Facts tags override the keyword rules', function (array $tags, array $expected) {
    $flags = (new DietClassifier)->classify('Spread', null, 'sugar, milk, hazelnuts', $tags);

    expect($flags)->toMatchArray($expected);
})->with([
    'vegan tag' => [['en:vegan'], ['vegan' => true, 'vegetarian' => true]],
    'non-vegan tag' => [['en:non-vegan'], ['vegan' => false]],
    'vegetarian tag' => [['en:vegetarian'], ['vegetarian' => true]],
    'non-vegetarian tag' => [['en:non-vegetarian'], ['vegetarian' => false, 'vegan' => false]],
    'gluten allergen' => [['en:gluten'], ['gluten_free' => false]],
    'milk allergen' => [['en:milk'], ['dairy_free' => false]],
]);

it('FOOD-027 uses tags alone when a packaged product has no ingredients text', function () {
    $flags = (new DietClassifier)->classify('Mystery', null, null, ['en:vegan']);

    expect($flags)->toBe(foodsDiets(['vegan' => true, 'vegetarian' => true]));
});

it('FOOD-027 keeps USDA prepared dishes and branded products out of every diet, whatever the category', function (string $name, string $category) {
    expect((new DietClassifier)->classify($name, $category, null, [], generic: true))->toBe(FOODS_ALL_DIETS_FALSE);
})->with([
    'mayonnaise' => ['Salad dressing, mayonnaise, regular', 'Fats and Oils'],
    'ranch' => ['Salad dressing, ranch dressing, regular', 'Fats and Oils'],
    'caesar' => ['Salad dressing, caesar dressing, regular', 'Fats and Oils'],
    'spinach souffle' => ['Spinach souffle', 'Vegetables and Vegetable Products'],
    'potato pancakes' => ['Potato pancakes', 'Vegetables and Vegetable Products'],
    'coleslaw' => ['Coleslaw, home-prepared', 'Vegetables and Vegetable Products'],
    'fruit salad' => ['Fruit salad, (peach and pear and apricot and pineapple and cherry), canned, water pack, solids and liquids', 'Fruits and Fruit Juices'],
    'bean soup' => ['Soup, bean with pork, canned, condensed', 'Legumes and Legume Products'],
    'branded supplement drink' => ['Beverages, ABBOTT, ENSURE PLUS, ready-to-drink', 'Beverages'],
    'drink mix' => ['Beverages, Orange-flavor drink, breakfast type, powder', 'Beverages'],
    'vegetarian patties' => ['Vegetarian meatloaf or patties', 'Legumes and Legume Products'],
    'meatballs' => ['Meatballs, frozen, Italian style', 'Beef Products'],
]);

it('FOOD-027 still flags single-ingredient plant foods whose names are close to a prepared marker', function (string $name, string $category) {
    expect((new DietClassifier)->classify($name, $category, null, [], generic: true))->toMatchArray(['vegan' => true, 'gluten_free' => true]);
})->with([
    'mixed vegetables' => ['Vegetables, mixed, frozen, unprepared', 'Vegetables and Vegetable Products'],
    'applesauce' => ['Applesauce, canned, unsweetened, without added ascorbic acid', 'Fruits and Fruit Juices'],
    'coffee prepared with water' => ['Beverages, coffee, brewed, prepared with tap water', 'Beverages'],
]);

it('FOOD-027 rules out gluten free and grain free for gluten words and compounds in ingredients', function (string $ingredients) {
    $flags = (new DietClassifier)->classify('Some product', null, $ingredients, []);

    expect($flags['gluten_free'])->toBeFalse($ingredients)->and($flags['grain_free'])->toBeFalse($ingredients);
})->with([
    'gluten' => ['water, gluten, salt'],
    'vital gluten' => ['WATER, VITAL GLUTEN, SALT'],
    'triticale' => ['triticale, water'],
    'oatmeal' => ['OATMEAL, RAISINS'],
    'breadcrumbs' => ['chicken, breadcrumbs, salt'],
    'flatbread' => ['flatbread, hummus'],
    'shortbread' => ['shortbread pieces, sugar'],
    'malted milk' => ['malted milk powder, sugar'],
    'durum' => ['durum semolina'],
]);

it('FOOD-027 keeps the high-FODMAP word of a plant milk or butter, and lets the dairy word go', function (string $name, ?string $category, ?string $ingredients, bool $lowFodmap) {
    $flags = (new DietClassifier)->classify($name, $category, $ingredients, [], generic: $ingredients === null);

    expect($flags['low_fodmap'])->toBe($lowFodmap)->and($flags['dairy_free'])->toBeTrue();
})->with([
    'generic cashew butter' => ['Nuts, cashew butter, plain, without salt added', 'Nut and Seed Products', null, false],
    'cashew butter ingredient' => ['Spread', null, 'cashew butter, salt', false],
    'cashew milk ingredient' => ['Drink', null, 'cashew milk, water', false],
    'butter beans ingredient' => ['Beans', null, 'butter beans, water, salt', false],
    'almond milk stays low FODMAP' => ['Drink', null, 'almond milk, water', true],
    'peanut butter stays low FODMAP' => ['Spread', null, 'peanut butter, salt', true],
]);

it('FOOD-027 leaves substitute categories out, so plant milks and meat substitutes keep their flags', function (string $category) {
    $flags = (new DietClassifier)->classify('Product', $category, 'filtered water, almonds, pea protein, salt', []);

    expect($flags)->toMatchArray(['dairy_free' => true, 'vegetarian' => true, 'vegan' => true]);
})->with([
    'USDA plant based milk' => ['Plant Based Milk'],
    'USDA vegetarian meats' => ['Vegetarian Frozen Meats'],
    'OFF milk alternatives' => ['plant based foods and beverages, beverages, plant based milk alternatives, almond milks'],
    'OFF meat analogues' => ['plant based foods, meat analogues, vegetarian sausages'],
    'OFF milk substitutes' => ['dairy substitutes, milk substitutes'],
]);

it('FOOD-027 still lets a real category rule a diet out next to a substitute one', function () {
    expect((new DietClassifier)->classify('Product', 'cheeses, vegan cheeses', 'water, salt', [])['dairy_free'])->toBeFalse();
});

it('FOOD-027 knows named cheeses and dairy solids, cured meats and animal additives', function (string $ingredients, array $false) {
    $flags = (new DietClassifier)->classify('Product', null, $ingredients, []);

    expect(array_intersect_key($flags, array_flip($false)))->toBe(array_fill_keys($false, false));
})->with([
    'ricotta' => ['RICOTTA, SPINACH, SALT', ['dairy_free', 'vegan']],
    'mascarpone' => ['mascarpone, sugar', ['dairy_free', 'vegan']],
    'mozzarella' => ['mozzarella, tomato', ['dairy_free', 'vegan']],
    'parmesan' => ['parmesan, basil', ['dairy_free', 'vegan']],
    'paneer' => ['paneer, spices', ['dairy_free', 'vegan']],
    'curd' => ['curd, water', ['dairy_free', 'vegan']],
    'milkfat' => ['MILKFAT, SUGAR', ['dairy_free', 'vegan']],
    'pancetta' => ['PANCETTA, PEPPER', ['vegetarian', 'vegan']],
    'chorizo' => ['chorizo, rice', ['vegetarian', 'vegan']],
    'prosciutto' => ['prosciutto, melon', ['vegetarian', 'vegan']],
    'mutton' => ['MUTTON, ONION', ['vegetarian', 'vegan']],
    'goat meat' => ['goat meat, spices', ['vegetarian', 'vegan']],
    'rabbit' => ['rabbit, herbs', ['vegetarian', 'vegan']],
    'rennet' => ['milk, salt, rennet', ['vegetarian', 'vegan']],
    'carmine' => ['sugar, carmine', ['vegetarian', 'vegan']],
    'collagen' => ['collagen, water', ['vegetarian', 'vegan']],
]);

it('FOOD-027 keeps bean curd and goat cheese in the diets they belong to', function () {
    $tofu = (new DietClassifier)->classify('Tofu', null, 'bean curd, water', []);
    $goatCheese = (new DietClassifier)->classify('Cheese', null, 'goat milk, salt, cultures', []);

    expect($tofu)->toMatchArray(['dairy_free' => true, 'vegan' => true])
        ->and($goatCheese)->toMatchArray(['vegetarian' => true, 'dairy_free' => false]);
});

it('FOOD-027 counts corn and pseudo-grain words in ingredients as grains', function (string $ingredients) {
    $flags = (new DietClassifier)->classify('Product', null, $ingredients, []);

    expect($flags['grain_free'])->toBeFalse($ingredients)->and($flags['paleo'])->toBeFalse($ingredients)->and($flags['gluten_free'])->toBeTrue($ingredients);
})->with(['cornmeal, salt', 'cornflour, water', 'polenta, salt', 'grits, salt', 'amaranth, water', 'teff, water', 'masa, water']);

it('FOOD-027 treats soybeans as a high-FODMAP legume, not paleo', function (string $name, string $category, ?string $ingredients) {
    $flags = (new DietClassifier)->classify($name, $category, $ingredients, [], generic: $ingredients === null);

    expect($flags['low_fodmap'])->toBeFalse()->and($flags['paleo'])->toBeFalse()->and($flags['vegan'])->toBeTrue();
})->with([
    'generic soybeans' => ['Soybeans, mature seeds, raw', 'Legumes and Legume Products', null],
    'soybean ingredient' => ['Snack', '', 'roasted soybeans, salt'],
]);

it('FOOD-027 treats oats as gluten in ingredients, as for generic foods, since they are usually cross-contaminated', function (string $ingredients) {
    $flags = (new DietClassifier)->classify('Granola', null, $ingredients, []);

    expect($flags['gluten_free'])->toBeFalse()->and($flags['grain_free'])->toBeFalse()->and($flags['vegan'])->toBeTrue();
})->with([
    'rolled oats' => ['rolled oats, sugar, salt'],
    'oat flour' => ['oat flour, water'],
]);

it('FOOD-027 treats breaded and battered foods as gluten and grain', function (string $name, string $category) {
    $flags = (new DietClassifier)->classify($name, $category, null, [], generic: true);

    expect($flags['gluten_free'])->toBeFalse()->and($flags['grain_free'])->toBeFalse()->and($flags['paleo'])->toBeFalse();
})->with([
    'batter' => ['Chicken, broilers or fryers, drumstick, meat and skin, cooked, fried, batter', 'Poultry Products'],
    'breaded' => ['Fish, catfish, channel, cooked, breaded and fried', 'Finfish and Shellfish Products'],
    'onion rings' => ['Onion rings, breaded, par fried, frozen, unprepared', 'Vegetables and Vegetable Products'],
]);

it('FOOD-027 only lets egg foods be dairy free or paleo in Dairy and Egg Products', function (string $name, bool $dairyFree) {
    $flags = (new DietClassifier)->classify($name, 'Dairy and Egg Products', null, [], generic: true);

    expect($flags['dairy_free'])->toBe($dairyFree)->and($flags['paleo'])->toBe($dairyFree);
})->with([
    'eggnog' => ['Eggnog', false],
    'dulce de leche' => ['Dulce de Leche', false],
    'egg' => ['Egg, whole, raw, fresh', true],
]);

it('FOOD-027 treats whey as high FODMAP', function () {
    expect((new DietClassifier)->classify('Whey, sweet, dried', 'Dairy and Egg Products', null, [], generic: true)['low_fodmap'])->toBeFalse()
        ->and((new DietClassifier)->classify('Snack', null, 'whey, salt', [])['low_fodmap'])->toBeFalse();
});

it('FOOD-027 rules out paleo for sweetened foods and sodas', function (string $name, string $category, bool $paleo) {
    expect((new DietClassifier)->classify($name, $category, null, [], generic: true)['paleo'])->toBe($paleo);
})->with([
    'cola' => ['Beverages, carbonated, cola, regular', 'Beverages', false],
    'sweetened applesauce' => ['Applesauce, canned, sweetened, without salt', 'Fruits and Fruit Juices', false],
    'unsweetened applesauce' => ['Applesauce, canned, unsweetened, without added ascorbic acid', 'Fruits and Fruit Juices', true],
]);

it('FOOD-027 makes fresh, egg and noodle pasta non-vegan', function (string $name, bool $vegan) {
    $flags = (new DietClassifier)->classify($name, 'Cereal Grains and Pasta', null, [], generic: true);

    expect($flags['vegan'])->toBe($vegan);
})->with([
    'fresh pasta' => ['Pasta, fresh-refrigerated, plain, as purchased', false],
    'egg noodles' => ['Noodles, egg, dry, enriched', false],
    'dry pasta' => ['Pasta, dry, enriched', true],
]);

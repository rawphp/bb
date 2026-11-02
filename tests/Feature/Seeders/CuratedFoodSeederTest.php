<?php

use App\Domain\MealFit\CuratedGroup;
use App\Models\Food;
use App\Models\FoodServing;
use Database\Seeders\CuratedFoodSeeder;

beforeEach(fn () => $this->seed(CuratedFoodSeeder::class));

it('FOOD-002 seeds at least 150 curated generic foods from the curated source', function () {
    $curated = Food::where('curated', true);

    expect($curated->count())->toBeGreaterThanOrEqual(150)
        ->and(Food::where('source', 'curated')->count())->toBe($curated->count())
        ->and(Food::where('curated', true)->where('kind', '!=', 'generic')->count())->toBe(0)
        ->and(Food::where('source', 'curated')->pluck('source_id')->every(fn ($id) => $id === str($id)->slug()->toString()))->toBeTrue();
});

it('FOOD-003 fills every curated group', function (CuratedGroup $group) {
    expect(Food::where('curated_group', $group->value)->count())->toBeGreaterThanOrEqual(12);
})->with(CuratedGroup::cases());

it('FOOD-003 gives every curated food a group and unique name', function () {
    expect(Food::where('curated', true)->whereNull('curated_group')->count())->toBe(0)
        ->and(Food::where('curated', true)->distinct('name')->count('name'))->toBe(Food::where('curated', true)->count());
});

it('FOOD-004 stores positive macros per 100 g and household servings in grams', function () {
    $egg = Food::where('name', 'Whole egg')->firstOrFail();

    expect($egg->kcal)->toBeGreaterThan(100.0)
        ->and($egg->servings->mapWithKeys(fn ($s) => [$s->label => $s->grams])->all())->toBe(['egg' => 50.0]);

    expect(Food::where('curated', true)->where('kcal', '<=', 0)->count())->toBe(0)
        ->and(FoodServing::where('grams', '<=', 0)->count())->toBe(0);
});

it('FOOD-006 gives foods that change weight when cooked a cooked ratio', function (string $name, float $ratio) {
    expect(Food::where('name', $name)->firstOrFail()->cooked_ratio)->toBe($ratio);
})->with([
    ['Chicken breast', 0.75],
    ['Salmon', 0.8],
    ['White rice', 3.0],
    ['Pasta', 2.2],
    ['Quinoa', 2.8],
    ['Potato', 0.85],
]);

it('FOOD-006 keeps every cooked ratio plausible and leaves oats and oils without one', function () {
    expect(Food::whereNotNull('cooked_ratio')->where(fn ($q) => $q->where('cooked_ratio', '<', 0.3)->orWhere('cooked_ratio', '>', 4))->count())->toBe(0)
        ->and(Food::whereNotNull('cooked_ratio')->count())->toBeGreaterThanOrEqual(25)
        ->and(Food::where('name', 'Rolled oats')->firstOrFail()->cooked_ratio)->toBeNull()
        ->and(Food::where('name', 'Olive oil')->firstOrFail()->cooked_ratio)->toBeNull();
});

it('FOOD-007 treats cooking oils as ordinary curated fat foods with a tablespoon', function (string $name) {
    $oil = Food::where('name', $name)->firstOrFail();

    expect($oil->curated)->toBeTrue()
        ->and($oil->curated_group)->toStartWith('fat_')
        ->and($oil->fat)->toBeGreaterThan(95.0)
        ->and($oil->servings->pluck('label'))->toContain('tbsp');
})->with(['Olive oil', 'Avocado oil', 'Canola oil', 'Coconut oil']);

it('FOOD-003 puts olive oil in the recommended fats and coconut oil and butter in use sparingly', function () {
    expect(Food::where('name', 'Olive oil')->value('curated_group'))->toBe('fat_recommended')
        ->and(Food::where('name', 'Coconut oil')->value('curated_group'))->toBe('fat_sparingly')
        ->and(Food::where('name', 'Butter')->value('curated_group'))->toBe('fat_sparingly');
});

it('FOOD-008 includes drinks', function () {
    expect(Food::where('curated', true)->where('name', 'Cola')->exists())->toBeTrue();
});

it('FOOD-027 derives diet flags for curated foods', function () {
    $chicken = Food::where('name', 'Chicken breast')->firstOrFail();
    $oil = Food::where('name', 'Olive oil')->firstOrFail();
    $rice = Food::where('name', 'White rice')->firstOrFail();

    expect($chicken->vegan)->toBeFalse()->and($chicken->paleo)->toBeTrue()
        ->and($oil->vegan)->toBeTrue()
        ->and($rice->grain_free)->toBeFalse()->and($rice->gluten_free)->toBeTrue();
});

it('FOOD-002 is idempotent', function () {
    $foods = Food::count();
    $servings = FoodServing::count();

    $this->seed(CuratedFoodSeeder::class);

    expect(Food::count())->toBe($foods)->and(FoodServing::count())->toBe($servings);
});

it('FOOD-002 is part of the default seed', function () {
    Food::query()->forceDelete();

    $this->seed();

    expect(Food::where('curated', true)->count())->toBeGreaterThanOrEqual(150);
});

it('FOOD-027 sets curated diet flags by food knowledge, not by name keywords', function (string $name, array $true) {
    $food = Food::where('name', $name)->firstOrFail();

    expect(collect(Food::DIETS)->filter(fn ($d) => $food->{$d})->values()->all())->toBe($true);
})->with([
    'brie' => ['Brie', ['gluten_free', 'grain_free', 'low_fodmap', 'vegetarian']],
    'parmesan' => ['Parmesan', ['gluten_free', 'grain_free', 'low_fodmap']],
    'quark' => ['Quark', ['gluten_free', 'grain_free', 'vegetarian']],
    'skyr' => ['Skyr', ['gluten_free', 'grain_free', 'vegetarian']],
    'whey protein powder' => ['Whey protein powder', ['gluten_free', 'grain_free', 'vegetarian']],
    'bagel' => ['Bagel', ['dairy_free', 'vegetarian']],
    'croissant' => ['Croissant', ['vegetarian']],
    'mayonnaise' => ['Mayonnaise', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian']],
    'beer' => ['Beer', ['dairy_free', 'vegetarian']],
    'gummy candy' => ['Gummy candy', ['dairy_free', 'gluten_free', 'grain_free']],
    'cola' => ['Cola', ['dairy_free', 'gluten_free', 'grain_free', 'vegetarian', 'vegan']],
    'strawberry jam' => ['Strawberry jam', ['dairy_free', 'gluten_free', 'grain_free', 'vegetarian', 'vegan']],
    'chicken breast' => ['Chicken breast', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'paleo']],
    'broccoli' => ['Broccoli', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan']],
    'cheddar' => ['Cheddar cheese', ['gluten_free', 'grain_free', 'low_fodmap', 'vegetarian']],
    'greek yogurt' => ['Greek yogurt nonfat', ['gluten_free', 'grain_free', 'vegetarian']],
    'skim milk' => ['Skim milk', ['gluten_free', 'grain_free', 'vegetarian']],
    'butter' => ['Butter', ['gluten_free', 'grain_free', 'low_fodmap', 'vegetarian']],
    'white sugar' => ['White sugar', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian']],
    'potato' => ['Potato', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'vegan']],
    'sugary cereal' => ['Sugary breakfast cereal', ['dairy_free']],
    'spinach' => ['Spinach', ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan']],
]);

it('FOOD-027 never contradicts itself on curated foods', function () {
    $foods = Food::where('curated', true)->get();

    expect($foods->filter(fn ($f) => $f->vegan && ! ($f->vegetarian && $f->dairy_free))->pluck('name')->all())->toBe([])
        ->and($foods->filter(fn ($f) => $f->paleo && ! ($f->dairy_free && $f->grain_free))->pluck('name')->all())->toBe([])
        ->and($foods->filter(fn ($f) => str_contains(strtolower($f->name), 'bread') && $f->gluten_free)->pluck('name')->all())->toBe([]);
});

it('FOOD-027 never marks a dairy curated food as dairy free or vegan', function () {
    $dairy = '/cheese|milk|yogurt|yoghurt|butter|cream|whey|casein|skyr|quark|kefir/i';
    $plant = '/(peanut|almond|cashew|nut|seed|cocoa|shea) butter|coconut (milk|cream)|(almond|oat|soy|soya|rice|hemp) milk/i';

    $offenders = Food::where('curated', true)->get()
        ->filter(fn ($f) => preg_match($dairy, $f->name) && ! preg_match($plant, $f->name) && ($f->dairy_free || $f->vegan))
        ->pluck('name')->all();

    expect($offenders)->toBe([]);
});

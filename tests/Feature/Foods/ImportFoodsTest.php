<?php

use App\Actions\Foods\SaveImportedFoods;
use App\Models\Food;
use App\Models\FoodServing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function foodsFixture(string $name): string
{
    return base_path('tests/fixtures/foods/'.$name);
}

/** @return list<array{string, float}> */
function foodsServingsOf(Food $food): array
{
    return $food->servings()->orderBy('id')->get()->map(fn (FoodServing $s) => [$s->label, $s->grams])->all();
}

it('FOOD-001 imports a USDA dump as shared foods with their servings', function () {
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_foundation.json')])
        ->expectsOutput('Imported 2 foods, skipped 0.')
        ->assertSuccessful();

    $banana = Food::query()->where('source', 'usda')->where('source_id', '1105073')->firstOrFail();
    expect(Food::count())->toBe(2)
        ->and($banana->user_id)->toBeNull()
        ->and($banana->curated)->toBeFalse()
        ->and($banana->kcal)->toBe(97.0)
        ->and($banana->vegan)->toBeTrue()
        ->and(foodsServingsOf($banana))->toBe([['medium', 118.0], ['1 cup, sliced', 150.0]]);
});

it('FOOD-008 imports SR Legacy and Branded USDA dumps', function () {
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_sr_legacy.json')])->assertSuccessful();
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_branded.json')])->assertSuccessful();

    expect(Food::query()->where('source_id', '171890')->value('name'))->toBe('Beverages, coffee, brewed, prepared with tap water')
        ->and(Food::query()->where('source_id', '1990001')->value('barcode'))->toBe('12345678905')
        ->and(Food::query()->where('source_id', '1990002')->value('kind'))->toBe('restaurant');
});

it('FOOD-001 imports Open Food Facts JSONL, plain or gzipped, and skips products without a name', function (string $file) {
    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture($file)])
        ->expectsOutput('Imported 3 foods, skipped 1.')
        ->assertSuccessful();

    expect(Food::query()->where('source', 'off')->pluck('source_id')->sort()->values()->all())
        ->toBe(['3017620422003', '5000000000017', '7350000000012']);
})->with(['off.jsonl', 'off.jsonl.gz']);

it('FOOD-001 is idempotent: a second run leaves foods, ids and servings unchanged', function (string $source, string $file) {
    $this->artisan('foods:import', ['source' => $source, 'path' => foodsFixture($file)])->assertSuccessful();
    $servings = FoodServing::count();
    $ids = Food::query()->orderBy('id')->pluck('id')->all();

    $this->artisan('foods:import', ['source' => $source, 'path' => foodsFixture($file)])->assertSuccessful();

    expect(Food::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(FoodServing::count())->toBe($servings);
})->with([
    ['usda', 'usda_foundation.json'],
    ['usda', 'usda_sr_legacy.json'],
    ['usda', 'usda_branded.json'],
    ['off', 'off.jsonl'],
    ['off', 'off.jsonl.gz'],
]);

it('FOOD-002 never touches the curation of an already imported row', function () {
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_foundation.json')])->assertSuccessful();
    Food::query()->where('source_id', '1105073')->update(['curated' => true, 'curated_group' => 'carbs_recommended', 'cooked_ratio' => 1.5]);

    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_foundation.json')])->assertSuccessful();

    $banana = Food::query()->where('source_id', '1105073')->firstOrFail();
    expect($banana->curated)->toBeTrue()
        ->and($banana->curated_group)->toBe('carbs_recommended')
        ->and($banana->cooked_ratio)->toBe(1.5);
});

it('FOOD-001 refreshes changed values and servings on re-import', function () {
    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off.jsonl')])->assertSuccessful();
    Food::query()->where('source_id', '3017620422003')->update(['kcal' => 1]);
    FoodServing::query()->delete();

    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off.jsonl')])->assertSuccessful();

    expect(Food::query()->where('source_id', '3017620422003')->value('kcal'))->toBe(539.0)
        ->and(FoodServing::count())->toBe(2);
});

it('FOOD-001 keeps source ids as strings so leading zeros never collide', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (preg_match('/["`]source_id["`] in/', $query->sql)) {
            $queries[] = $query->bindings;
        }
    });

    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off_zeros.jsonl')])->assertSuccessful();
    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off_zeros.jsonl')])->assertSuccessful();

    expect($queries)->not->toBeEmpty()
        ->and(collect($queries)->flatten()->filter(fn ($b) => ! is_string($b))->all())->toBe([])
        ->and(foodsServingsOf(Food::query()->where('source_id', '0012345678905')->firstOrFail()))->toBe([['30 g', 30.0]])
        ->and(foodsServingsOf(Food::query()->where('source_id', '12345678905')->firstOrFail()))->toBe([['50 g', 50.0]])
        ->and(FoodServing::count())->toBe(2);
});

it('FOOD-001 upserts foods and replaces their servings, keeping the last of a repeated source id', function () {
    $row = fn (string $id, array $servings, string $name = 'Thing') => [
        'food' => ['name' => $name.' '.$id, 'source' => 'usda', 'source_id' => $id, 'kind' => 'generic',
            'kcal' => 10.0, 'protein' => 1.0, 'fat' => 1.0, 'carbs' => 1.0, 'fiber' => 0.0],
        'servings' => $servings,
    ];
    $save = new SaveImportedFoods;

    $save->handle([$row('007', [['label' => 'a', 'grams' => 1.0]]), $row('7', [['label' => 'b', 'grams' => 2.0]])]);
    $save->handle([$row('007', [['label' => 'x', 'grams' => 9.0]]), $row('007', [['label' => 'c', 'grams' => 3.0]], 'Last'), $row('7', [['label' => 'b', 'grams' => 2.0]])]);

    $zeros = Food::query()->where('source_id', '007')->firstOrFail();
    expect(Food::count())->toBe(2)
        ->and($zeros->name)->toBe('Last 007')
        ->and(foodsServingsOf($zeros))->toBe([['c', 3.0]])
        ->and(foodsServingsOf(Food::query()->where('source_id', '7')->firstOrFail()))->toBe([['b', 2.0]]);
});

it('FOOD-001 imports more than one batch: every food and serving once, the same again on a re-run', function () {
    $path = tempnam(sys_get_temp_dir(), 'off');
    $lines = '';
    for ($i = 1; $i <= 2001; $i++) {
        $lines .= json_encode(['code' => (string) (2000000000000 + $i), 'product_name' => "Product {$i}",
            'nutriments' => ['energy-kcal_100g' => 100], 'serving_quantity' => 30, 'serving_size' => '30 g'])."\n";
    }
    file_put_contents($path, $lines);

    try {
        foreach ([1, 2] as $run) {
            $this->artisan('foods:import', ['source' => 'off', 'path' => $path])
                ->expectsOutput('Imported 2001 foods, skipped 0.')
                ->assertSuccessful();

            expect(Food::count())->toBe(2001, "run {$run}")
                ->and(FoodServing::count())->toBe(2001, "run {$run}")
                ->and(FoodServing::query()->distinct()->count('food_id'))->toBe(2001, "run {$run}");
        }
    } finally {
        unlink($path);
    }
});

it('FOOD-001 counts a source id repeated in one file once', function () {
    $path = tempnam(sys_get_temp_dir(), 'off');
    $line = json_encode(['code' => '2000000000001', 'product_name' => 'Twice', 'nutriments' => ['energy-kcal_100g' => 100]])."\n";
    file_put_contents($path, $line.$line);

    try {
        $this->artisan('foods:import', ['source' => 'off', 'path' => $path])
            ->expectsOutput('Imported 1 foods, skipped 0.')
            ->assertSuccessful();
    } finally {
        unlink($path);
    }
});

it('FOOD-001 refreshes a soft-deleted imported food on re-import and leaves it deleted', function () {
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_foundation.json')])->assertSuccessful();
    $banana = Food::query()->where('source_id', '1105073')->firstOrFail();
    $banana->servings()->delete();
    $banana->update(['kcal' => 1.0]);
    $banana->delete();

    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_foundation.json')])
        ->expectsOutput('Imported 2 foods, skipped 0.')
        ->assertSuccessful();

    $banana = Food::withTrashed()->findOrFail($banana->id);
    expect($banana->trashed())->toBeTrue()
        ->and($banana->kcal)->toBe(97.0)
        ->and(foodsServingsOf($banana))->toBe([['medium', 118.0], ['1 cup, sliced', 150.0]])
        ->and(Food::withTrashed()->count())->toBe(2);
});

it('FOOD-001 fails a cut-off Open Food Facts download, plain or gzipped, so the install fetches it again', function (bool $gzip) {
    $lines = '';
    for ($i = 1; $i <= 3000; $i++) {
        $lines .= json_encode(['code' => (string) (2000000000000 + $i), 'product_name' => str_repeat('Product ', 8).$i,
            'nutriments' => ['energy-kcal_100g' => 100]])."\n";
    }
    $data = $gzip ? gzencode($lines, 9) : $lines;
    $path = tempnam(sys_get_temp_dir(), 'off').($gzip ? '.jsonl.gz' : '.jsonl');
    file_put_contents($path, substr($data, 0, intdiv(strlen($data), 2)));

    try {
        $this->artisan('foods:import', ['source' => 'off', 'path' => $path])
            ->expectsOutputToContain('is truncated or corrupt (1 lines are not valid JSON)')
            ->assertFailed();
    } finally {
        unlink($path);
    }
})->with(['plain' => false, 'gzipped' => true]);

it('FOOD-001 rolls the foods back when their servings cannot be written', function () {
    $rows = [[
        'food' => ['name' => 'Broken', 'source' => 'usda', 'source_id' => '1', 'kind' => 'generic',
            'kcal' => 10.0, 'protein' => 1.0, 'fat' => 1.0, 'carbs' => 1.0, 'fiber' => 0.0],
        'servings' => [['label' => null, 'grams' => 1.0]],
    ]];

    expect(fn () => (new SaveImportedFoods)->handle($rows))->toThrow(QueryException::class)
        ->and(Food::count())->toBe(0);
});

it('FOOD-001 fails on an unknown source, a missing file or a file of the wrong kind', function (string $source, string $file) {
    $this->artisan('foods:import', ['source' => $source, 'path' => foodsFixture($file)])->assertFailed();

    expect(Food::count())->toBe(0);
})->with([
    'unknown source' => ['nope', 'off.jsonl'],
    'missing jsonl' => ['off', 'missing.jsonl'],
    'missing json' => ['usda', 'missing.json'],
    'not a USDA file' => ['usda', 'off.jsonl'],
    'every row skipped' => ['off', 'usda_foundation.json'],
]);

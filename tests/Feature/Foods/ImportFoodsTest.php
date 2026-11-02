<?php

use App\Actions\Foods\SaveImportedFoods;
use App\Domain\Foods\FoodSource;
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

it('FOOD-001 gives every imported Open Food Facts food the ODbL credit, and every USDA food its own', function () {
    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off.jsonl')])->assertSuccessful();
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_foundation.json')])->assertSuccessful();

    $credits = Food::query()->get()->groupBy('source')->map(fn ($foods) => $foods->map(fn (Food $food) => $food->attribution())->unique()->values()->all());
    expect($credits['off'])->toBe([FoodSource::OpenFoodFacts->attribution()])
        ->and($credits['off'][0])->toContain('Open Database License (ODbL)')
        ->and($credits['usda'])->toBe([FoodSource::Usda->attribution()]);
});

it('FOOD-001 reminds the operator after an Open Food Facts import to show the ODbL credit, and not after USDA', function () {
    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off.jsonl')])
        ->expectsOutputToContain(FoodSource::OpenFoodFacts->attribution())
        ->assertSuccessful();
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_foundation.json')])
        ->doesntExpectOutputToContain('ODbL')
        ->assertSuccessful();
});

it('FOOD-008 imports SR Legacy and Branded USDA dumps', function () {
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_sr_legacy.json')])->assertSuccessful();
    $this->artisan('foods:import', ['source' => 'usda', 'path' => foodsFixture('usda_branded.json')])->assertSuccessful();

    expect(Food::query()->where('source_id', '171890')->value('name'))->toBe('Beverages, coffee, brewed, prepared with tap water')
        ->and(Food::query()->where('source_id', '1990001')->value('barcode'))->toBe('12345678905')
        ->and(Food::query()->where('source_id', '1990002')->value('kind'))->toBe('restaurant');
});

it('FOOD-001 skips an over-long Open Food Facts code and imports the rest of the file', function () {
    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off_edge.jsonl')])
        ->expectsOutput('Imported 7 foods, skipped 2.')
        ->assertSuccessful();

    expect(Food::query()->where('source_id', 'like', str_repeat('1234567890', 6).'%')->exists())->toBeFalse()
        ->and(Food::query()->where('source_id', str_repeat('1234567890', 4))->exists())->toBeTrue();
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

it('FOOD-001 refreshes every imported column and the servings on re-import', function () {
    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off.jsonl')])->assertSuccessful();
    $columns = array_values(array_diff(SaveImportedFoods::UPDATABLE, ['updated_at']));
    $imported = Food::query()->where('source_id', '3017620422003')->firstOrFail()->only($columns);
    $stale = ['name' => 'Old name', 'brand' => 'Old brand', 'barcode' => '999', 'kind' => 'restaurant',
        'kcal' => 1, 'protein' => 0, 'fat' => 0, 'carbs' => 0, 'fiber' => 0];
    foreach (Food::DIETS as $diet) {
        $stale[$diet] = ! $imported[$diet];
    }
    expect(array_keys($stale))->toEqualCanonicalizing($columns);
    Food::query()->where('source_id', '3017620422003')->update($stale);
    FoodServing::query()->delete();

    $this->artisan('foods:import', ['source' => 'off', 'path' => foodsFixture('off.jsonl')])->assertSuccessful();

    expect(Food::query()->where('source_id', '3017620422003')->firstOrFail()->only($columns))->toBe($imported)
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

    $save([$row('007', [['label' => 'a', 'grams' => 1.0]]), $row('7', [['label' => 'b', 'grams' => 2.0]])]);
    $save([$row('007', [['label' => 'x', 'grams' => 9.0]]), $row('007', [['label' => 'c', 'grams' => 3.0]], 'Last'), $row('7', [['label' => 'b', 'grams' => 2.0]])]);

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

it('FOOD-001 fails a gzipped download that lost its end, even when every line it gives is whole', function () {
    $lines = '';
    for ($i = 1; $i <= 10; $i++) {
        $lines .= json_encode(['code' => (string) (2000000000000 + $i), 'product_name' => "Product {$i}", 'nutriments' => ['energy-kcal_100g' => 100]])."\n";
    }
    $path = tempnam(sys_get_temp_dir(), 'off').'.jsonl.gz';
    // The last 8 bytes are the gzip trailer: without them every line still inflates.
    file_put_contents($path, substr((string) gzencode($lines), 0, -8));

    try {
        $this->artisan('foods:import', ['source' => 'off', 'path' => $path])
            ->expectsOutputToContain('is truncated or corrupt')
            ->assertFailed();
        expect(Food::count())->toBe(10);
    } finally {
        unlink($path);
    }
});

it('FOOD-001 fails a gzipped download with a corrupt block that zlib refuses', function () {
    $lines = '';
    for ($i = 1; $i <= 10; $i++) {
        $lines .= json_encode(['code' => (string) (2000000000000 + $i), 'product_name' => "Product {$i}", 'nutriments' => ['energy-kcal_100g' => 100]])."\n";
    }
    $gzip = (string) gzencode($lines);
    // Byte 10 starts the deflate data: 0xFF names block type 3, which does not exist, so inflate fails.
    $gzip[10] = "\xFF";
    $path = tempnam(sys_get_temp_dir(), 'off').'.jsonl.gz';
    file_put_contents($path, $gzip);

    try {
        $this->artisan('foods:import', ['source' => 'off', 'path' => $path])
            ->expectsOutputToContain('is truncated or corrupt')
            ->assertFailed();
        expect(Food::count())->toBe(0);
    } finally {
        unlink($path);
    }
});

it('FOOD-001 re-imports one source without touching another source\'s food that has the same source id', function () {
    $row = fn (string $source, string $name, string $label) => [
        'food' => ['name' => $name, 'source' => $source, 'source_id' => '7', 'kind' => 'packaged',
            'kcal' => 10.0, 'protein' => 1.0, 'fat' => 1.0, 'carbs' => 1.0, 'fiber' => 0.0],
        'servings' => [['label' => $label, 'grams' => 10.0]],
    ];
    $save = new SaveImportedFoods;
    $save([$row('off', 'Off seven', 'off serving')]);

    $save([$row('usda', 'Usda seven', 'usda serving')]);
    $save([$row('usda', 'Usda seven', 'usda serving')]);

    $off = Food::query()->where('source', 'off')->where('source_id', '7')->firstOrFail();
    $usda = Food::query()->where('source', 'usda')->where('source_id', '7')->firstOrFail();
    expect($off->name)->toBe('Off seven')
        ->and(foodsServingsOf($off))->toBe([['off serving', 10.0]])
        ->and(foodsServingsOf($usda))->toBe([['usda serving', 10.0]])
        ->and(FoodServing::count())->toBe(2);
});

it('FOOD-001 fails an empty download, plain or gzipped, and imports nothing', function (bool $gzip) {
    $path = tempnam(sys_get_temp_dir(), 'off').($gzip ? '.jsonl.gz' : '.jsonl');
    file_put_contents($path, $gzip ? gzencode('') : '');

    try {
        $this->artisan('foods:import', ['source' => 'off', 'path' => $path])
            ->expectsOutputToContain('Imported 0 foods, skipped 0.')
            ->assertFailed();
        expect(Food::count())->toBe(0);
    } finally {
        unlink($path);
    }
})->with(['plain' => false, 'gzipped' => true]);

it('FOOD-001 fails a cut-off USDA download after the batches it read, so the install fetches it again', function () {
    $foods = [];
    for ($i = 1; $i <= 2500; $i++) {
        $foods[] = ['fdcId' => 3000000 + $i, 'description' => "Food {$i}, raw", 'foodCategory' => ['description' => 'Vegetables and Vegetable Products'],
            'foodNutrients' => [['nutrient' => ['number' => '208'], 'amount' => 50]]];
    }
    $json = json_encode(['FoundationFoods' => $foods]);
    $path = tempnam(sys_get_temp_dir(), 'usda').'.json';
    file_put_contents($path, substr($json, 0, intdiv(strlen($json), 2)));

    try {
        $this->artisan('foods:import', ['source' => 'usda', 'path' => $path])
            ->expectsOutputToContain('is truncated or corrupt')
            ->assertFailed();
        expect(Food::count())->toBeGreaterThan(0)->toBeLessThan(2500);
    } finally {
        unlink($path);
    }
});

it('FOOD-001 rolls the foods back when their servings cannot be written', function () {
    $rows = [[
        'food' => ['name' => 'Broken', 'source' => 'usda', 'source_id' => '1', 'kind' => 'generic',
            'kcal' => 10.0, 'protein' => 1.0, 'fat' => 1.0, 'carbs' => 1.0, 'fiber' => 0.0],
        'servings' => [['label' => null, 'grams' => 1.0]],
    ]];

    expect(fn () => (new SaveImportedFoods)($rows))->toThrow(QueryException::class)
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

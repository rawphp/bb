<?php

use App\Models\Exercise;
use App\Models\Food;
use App\Models\FoodServing;
use App\Models\Recipe;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

it('runs every area seeder in database/seeders by name', function () {
    expect(DatabaseSeeder::areaSeeders())->toContain(AccountSeeder::class)
        ->not->toContain(DatabaseSeeder::class);

    $this->seed();

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

it('seeds the known-password test account only in local and testing', function (string $environment, bool $seeded) {
    app()->instance('env', $environment);

    // Called directly, as `db:seed --force` would: the artisan command's own
    // production prompt is not the guard under test.
    app(AccountSeeder::class)->run();

    expect(User::where('email', 'test@example.com')->exists())->toBe($seeded);
})->with([
    'local' => ['local', true],
    'testing' => ['testing', true],
    'staging' => ['staging', false],
    'production' => ['production', false],
]);

it('FOOD-027 RCP-020 db:seed on an updated install corrects stale library diet flags without duplicating rows', function () {
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    $counts = fn () => [Food::count(), FoodServing::count(), Recipe::count(), DB::table('recipe_ingredients')->count(),
        Exercise::count(), Template::count(), TemplateSlot::count()];
    $before = $counts();

    // An install seeded before Bacon lost gluten free, grain free and low FODMAP.
    $stale = ['gluten_free' => true, 'grain_free' => true, 'low_fodmap' => true];
    Food::where('source', 'curated')->where('source_id', 'bacon')->update($stale);
    Recipe::where('slug', 'bacon-and-egg-breakfast-plate')->update($stale);

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(Food::where('source', 'curated')->where('source_id', 'bacon')->firstOrFail()->only(array_keys($stale)))
        ->toBe(array_fill_keys(array_keys($stale), false))
        ->and(Recipe::where('slug', 'bacon-and-egg-breakfast-plate')->firstOrFail()->only(array_keys($stale)))
        ->toBe(array_fill_keys(array_keys($stale), false))
        ->and($counts())->toBe($before);
});

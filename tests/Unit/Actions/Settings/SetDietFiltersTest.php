<?php

use App\Actions\Settings\SetDietFilters;
use App\Capabilities\Settings\Data\DietFilterInput;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRebuild;

// SET-006: the diet filters. The rebuild is FakeRebuild, to show it never runs.
uses(RefreshDatabase::class);

function filters(string ...$values): array
{
    return array_map(fn (string $value) => DietFilterInput::validate(['filter' => $value]), $values);
}

it('SET-006 diet filters are multi-select, kept in the list\'s order, each once', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['diet_filters' => []]);

    expect(app(SetDietFilters::class)($user, filters('vegan', 'dairy_free', 'vegan')))->toBeTrue()
        ->and(Profile::query()->where('user_id', $user->id)->firstOrFail()->diet_filters)->toBe(['dairy_free', 'vegan'])
        ->and(app(SetDietFilters::class)($user, filters('dairy_free', 'vegan')))->toBeFalse();

    app(SetDietFilters::class)($user, []);
    expect(Profile::query()->where('user_id', $user->id)->firstOrFail()->diet_filters)->toBe([]);
});

it('SET-006 diet filters never rebuild the targets, even while a rebuild is pending', function () {
    $rebuild = FakeRebuild::bind();
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['targets_rebuild_failed_at' => '2026-10-13 09:00:00']);

    app(SetDietFilters::class)($user, filters('paleo'));

    expect($rebuild->calls)->toBe([])
        ->and(Profile::query()->where('user_id', $user->id)->firstOrFail()->targets_rebuild_failed_at?->toDateTimeString())->toBe('2026-10-13 09:00:00');
});

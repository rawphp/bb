<?php

use App\Actions\Settings\UpdateProfile;
use App\Actions\Settings\UpdateSettings;
use App\Capabilities\Settings\Data\ProfileUpdateInput;
use App\Capabilities\Settings\Data\SettingsUpdateInput;
use App\Capabilities\Support\FieldRefused;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRebuild;

// SET-001 to SET-004: the profile form and the unit, appearance and totals settings. The rebuild is FakeRebuild.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function profileInput(array $height, array $changes = []): ProfileUpdateInput
{
    return ProfileUpdateInput::validate(array_replace([
        'name' => 'Sam', 'sex' => 'female', 'birth_date' => '1990-04-12', 'height' => $height,
        'body_fat_bucket' => 'low', 'uses_glp1' => false, 'experience' => 'intermediate',
    ], $changes));
}

function profileOwner(): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['name' => 'Sam', 'height_cm' => 168.0, 'height_unit' => 'cm']);

    return $user;
}

it('SET-004 SET-001 the height is stored in cm whichever unit it was typed in', function (array $height, float $cm) {
    FakeRebuild::bind();
    $user = profileOwner();

    app(UpdateProfile::class)($user, profileInput($height));

    expect(Profile::query()->where('user_id', $user->id)->value('height_cm'))->toEqual($cm);
})->with([
    'cm' => [['unit' => 'cm', 'cm' => 180.0, 'feet' => null, 'inches' => null], 180.0],
    'ft and in' => [['unit' => 'feetInches', 'cm' => null, 'feet' => 5, 'inches' => 6.0], 167.6],
    'only feet' => [['unit' => 'feetInches', 'cm' => null, 'feet' => 6, 'inches' => null], 182.9],
]);

it('SET-004 a height with no number for its unit is refused on height', function (array $height) {
    FakeRebuild::bind();

    expect(fn () => app(UpdateProfile::class)(profileOwner(), profileInput($height)))
        ->toThrow(fn (FieldRefused $refused) => expect($refused->field)->toBe('height'));
})->with([
    'cm missing' => [['unit' => 'cm', 'cm' => null, 'feet' => 5, 'inches' => 6.0]],
    'zero cm' => [['unit' => 'cm', 'cm' => 0.0, 'feet' => null, 'inches' => null]],
    'feet and inches missing' => [['unit' => 'feetInches', 'cm' => 170.0, 'feet' => null, 'inches' => null]],
]);

it('SET-004 a name of only spaces is refused on name', function () {
    FakeRebuild::bind();

    expect(fn () => app(UpdateProfile::class)(profileOwner(), profileInput(['unit' => 'cm', 'cm' => 170.0, 'feet' => null, 'inches' => null], ['name' => '   '])))
        ->toThrow(fn (FieldRefused $refused) => expect($refused->field)->toBe('name'));
});

it('SET-004 the profile form keeps the height unit setting and rebuilds only for a target input', function () {
    $rebuild = FakeRebuild::bind();
    $user = profileOwner();

    app(UpdateProfile::class)($user, profileInput(['unit' => 'feetInches', 'cm' => null, 'feet' => 5, 'inches' => 6.14], ['name' => 'Samantha']));

    expect(Profile::query()->where('user_id', $user->id)->value('height_unit'))->toBe('cm')
        ->and(Profile::query()->where('user_id', $user->id)->value('name'))->toBe('Samantha')
        ->and($rebuild->calls)->toBe([]);
});

it('SET-001 SET-002 SET-003 EX-012 the settings save on the profile and do not rebuild', function () {
    $rebuild = FakeRebuild::bind();
    $user = profileOwner();

    app(UpdateSettings::class)($user, SettingsUpdateInput::validate([
        'weight_unit' => 'lb', 'height_unit' => 'feetInches', 'appearance' => 'dark', 'consumed_totals' => 'checked_in', 'micro_plates' => true,
    ]));

    $profile = Profile::query()->where('user_id', $user->id)->firstOrFail();
    expect([$profile->weight_unit, $profile->height_unit, $profile->appearance, $profile->consumed_totals, $profile->micro_plates])
        ->toBe(['lb', 'feetInches', 'dark', 'checked_in', true])
        ->and($profile->height_cm)->toBe(168.0)
        ->and($rebuild->calls)->toBe([]);
});

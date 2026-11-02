<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\DayTarget;
use App\Models\Food;
use App\Models\ManualShoppingItem;
use App\Models\PlannedFood;
use App\Models\ShoppingTicks;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\ScreenFixture;

// The HTTP seam of shopping_show, shopping_item_check, shopping_item_add and shopping_item_delete.
// The rules are unit tests (ShoppingScreenTest, TickedRangeTest, PlannedShoppingTest,
// ShoppingActionsTest, ShoppingInputTest).

afterEach(fn () => Carbon::setTestNow());

function shoppingCall(?User $user, string $name, array $input = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function shoppingPlan(User $user, string $date, Food $food, float $grams): void
{
    $meal = DayTarget::factory()->for($user)->create(['date' => $date, 'meals' => [['name' => 'Lunch']]])->mealIndex()->firstOrFail();
    PlannedFood::factory()->create(['user_id' => $user->id, 'meal_id' => $meal->id, 'food_id' => $food->id, 'grams' => $grams]);
}

it('SHOP-005 AGT-010 shopping_item_delete is destructive, none is reversible, and each write affects shopping_show', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (['shopping_item_check', 'shopping_item_add', 'shopping_item_delete'] as $name) {
        expect(HostDefinition::affects($registry->get($name)))->toBe(['shopping_show'])
            ->and(HostDefinition::isReversible($registry->get($name)))->toBeFalse()
            ->and(HostDefinition::isDestructive($registry->get($name)))->toBe($name === 'shopping_item_delete');
    }
    expect($registry->get('shopping_show')->readOnly)->toBeTrue();
});

it('SHOP-001 refuses every shopping capability when signed out', function (string $name, array $input) {
    shoppingCall(null, $name, $input)->assertUnauthorized();
})->with([
    'shopping_show' => ['shopping_show', []],
    'shopping_item_check' => ['shopping_item_check', ['from' => '2026-11-02', 'to' => '2026-11-08', 'key' => 'food:01JBX5F0000000000000000002', 'ticked' => true]],
    'shopping_item_add' => ['shopping_item_add', ['id' => '01JBX5H0000000000000000001', 'text' => 'Paper towels']],
    'shopping_item_delete' => ['shopping_item_delete', ['id' => '01JBX5H0000000000000000001']],
]);

it('SHOP-001 SHOP-005 shows the next 7 days of planned foods by store category, with ticks, manual items and the text to share', function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    $user = User::factory()->create();
    $chicken = Food::factory()->create(['name' => 'Chicken breast']);
    foreach (range(2, 8) as $day) {
        shoppingPlan($user, sprintf('2026-11-%02d', $day), $chicken, 300);
    }
    $eggWhites = Food::factory()->create(['name' => 'Egg whites']);
    shoppingPlan($user, '2026-11-03', $eggWhites, 1000);
    $rice = Food::factory()->create(['name' => 'White rice']);
    shoppingPlan($user, '2026-11-04', $rice, 1050);
    shoppingPlan($user, '2026-11-09', $rice, 500);
    $towels = (string) Str::ulid();

    shoppingCall($user, 'shopping_item_add', ['id' => $towels, 'text' => 'Paper towels'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['shopping_show{}']);
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-02', 'to' => '2026-11-08', 'key' => "food:{$chicken->id}", 'ticked' => true])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['shopping_show{}', 'shopping_show{"from":"2026-11-02","to":"2026-11-08"}']);

    $response = shoppingCall($user, 'shopping_show')->assertOk();

    expect($response->json('data'))->toMatchArray([
        'from' => '2026-11-02',
        'to' => '2026-11-08',
        'range_text' => 'Nov 2 – Nov 8',
        'share_text' => "Protein\nEgg whites — 1000 g\n\nGrains and bread\nWhite rice — 1100 g\n\nOther\nPaper towels",
        'empty_text' => null,
    ])
        ->and(array_column($response->json('data.sections'), 'category'))->toBe(['protein', 'grains_and_bread', 'other'])
        ->and($response->json('data.sections.0'))->toBe(['category' => 'protein', 'title' => 'Protein', 'rows' => [
            ['key' => "food:{$chicken->id}", 'name' => 'Chicken breast', 'amount_text' => '2100 g', 'is_ticked' => true, 'is_manual' => false],
            ['key' => "food:{$eggWhites->id}", 'name' => 'Egg whites', 'amount_text' => '1000 g', 'is_ticked' => false, 'is_manual' => false],
        ]])
        ->and($response->json('data.sections.2.rows'))->toBe([
            ['key' => "manual:{$towels}", 'name' => 'Paper towels', 'amount_text' => null, 'is_ticked' => false, 'is_manual' => true],
        ]);
    assertMatchesScreenFixture('shopping_show', $response->json('data'));

    // The range asked for is the one shown, and the week's ticks stay with that week.
    $later = shoppingCall($user, 'shopping_show', ['from' => '2026-11-04', 'to' => '2026-11-09'])->assertOk();
    expect($later->json('data.range_text'))->toBe('Nov 4 – Nov 9')
        ->and($later->json('data.sections.0.rows.0.is_ticked'))->toBeFalse()
        ->and($later->json('data.sections.1.rows.0.amount_text'))->toBe('1600 g')
        ->and($later->json('data.sections.2.rows.0.key'))->toBe("manual:{$towels}");

    // A tick in that range leaves the first week's ticks as they were.
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-04', 'to' => '2026-11-09', 'key' => "food:{$rice->id}", 'ticked' => true])->assertOk();
    $week = shoppingCall($user, 'shopping_show', ['from' => '2026-11-02', 'to' => '2026-11-08'])->assertOk();
    expect($week->json('data.sections.0.rows.0.is_ticked'))->toBeTrue()
        ->and($week->json('data.sections.1.rows.0.is_ticked'))->toBeFalse();
});

it('SHOP-001 says nothing is planned for an empty range', function () {
    Carbon::setTestNow('2026-11-02 09:00:00');

    $response = shoppingCall(User::factory()->create(), 'shopping_show')->assertOk();

    expect($response->json('data'))->toBe([
        'from' => '2026-11-02', 'to' => '2026-11-08', 'range_text' => 'Nov 2 – Nov 8',
        'sections' => [], 'share_text' => '', 'empty_text' => 'No foods planned for these days.',
    ]);
    expect(ScreenFixture::differences(ScreenFixture::load('shopping_show.empty'), $response->json('data')))->toBe([]);
    assertMatchesScreenFixture('shopping_show', $response->json('data'));
});

it('SHOP-005 brings back the range ticked in while it runs, then the next 7 days, with the manual items on both', function () {
    Carbon::setTestNow('2026-11-04 09:00:00');
    $user = User::factory()->create();
    $broccoli = Food::factory()->create(['name' => 'Broccoli']);
    shoppingPlan($user, '2026-11-05', $broccoli, 200);
    $soap = ManualShoppingItem::factory()->for($user)->create(['text' => 'Soap']);
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-04', 'to' => '2026-11-06', 'key' => "food:{$broccoli->id}", 'ticked' => true])->assertOk();

    $saved = shoppingCall($user, 'shopping_show')->assertOk();
    Carbon::setTestNow('2026-11-07 09:00:00');
    $ended = shoppingCall($user, 'shopping_show')->assertOk();

    expect([$saved->json('data.from'), $saved->json('data.to')])->toBe(['2026-11-04', '2026-11-06'])
        ->and($saved->json('data.sections.0.rows.0.is_ticked'))->toBeTrue()
        ->and([$ended->json('data.from'), $ended->json('data.to')])->toBe(['2026-11-07', '2026-11-13'])
        ->and($ended->json('data.sections'))->toBe([['category' => 'other', 'title' => 'Other', 'rows' => [
            ['key' => "manual:{$soap->id}", 'name' => 'Soap', 'amount_text' => null, 'is_ticked' => false, 'is_manual' => true],
        ]]]);
});

it('SHOP-005 a late tick, untick or delete in an earlier week does not move the list shown with no dates', function () {
    Carbon::setTestNow('2026-11-04 09:00:00');
    $user = User::factory()->create();
    $soap = ManualShoppingItem::factory()->for($user)->create(['text' => 'Soap']);
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-01', 'to' => '2026-11-07', 'key' => "manual:{$soap->id}", 'ticked' => true])->assertOk();
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-03', 'to' => '2026-11-09', 'key' => 'food:01JBX5F0000000000000000002', 'ticked' => true])->assertOk();

    Carbon::setTestNow('2026-11-04 10:00:00');
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-01', 'to' => '2026-11-07', 'key' => 'food:01JBX5F0000000000000000007', 'ticked' => true])->assertOk();
    shoppingCall($user, 'shopping_item_delete', ['id' => $soap->id])->assertOk();

    $response = shoppingCall($user, 'shopping_show')->assertOk();
    expect([$response->json('data.from'), $response->json('data.to')])->toBe(['2026-11-03', '2026-11-09']);
});

it('SHOP-005 taking back the last tick in a range keeps that range as the list shown with no dates', function () {
    Carbon::setTestNow('2026-11-04 09:00:00');
    $user = User::factory()->create();
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-03', 'to' => '2026-11-09', 'key' => 'food:01JBX5F0000000000000000002', 'ticked' => true])->assertOk();
    Carbon::setTestNow('2026-11-06 09:00:00');
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-03', 'to' => '2026-11-09', 'key' => 'food:01JBX5F0000000000000000002', 'ticked' => false])->assertOk();

    $response = shoppingCall($user, 'shopping_show')->assertOk();
    expect([$response->json('data.from'), $response->json('data.to')])->toBe(['2026-11-03', '2026-11-09']);
});

it('SHOP-001 a tick planning next week leaves this week\'s list as the one shown with no dates', function () {
    Carbon::setTestNow('2026-11-04 09:00:00');
    $user = User::factory()->create();
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-04', 'to' => '2026-11-10', 'key' => 'food:01JBX5F0000000000000000002', 'ticked' => true])->assertOk();
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-11', 'to' => '2026-11-17', 'key' => 'food:01JBX5F0000000000000000007', 'ticked' => true])->assertOk();

    $response = shoppingCall($user, 'shopping_show')->assertOk();
    expect([$response->json('data.from'), $response->json('data.to')])->toBe(['2026-11-04', '2026-11-10']);
});

it('SHOP-001 SHOP-005 shows only the user\'s own manual items and ticks', function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $broccoli = Food::factory()->create(['name' => 'Broccoli']);
    shoppingPlan($user, '2026-11-03', $broccoli, 200);
    ManualShoppingItem::factory()->for($other)->create(['text' => 'Their soap']);
    ShoppingTicks::factory()->for($other)->create(['starts_on' => '2026-11-02', 'ends_on' => '2026-11-08', 'keys' => ["food:{$broccoli->id}"]]);

    $response = shoppingCall($user, 'shopping_show')->assertOk();

    expect($response->json('data.sections'))->toBe([['category' => 'produce', 'title' => 'Produce', 'rows' => [
        ['key' => "food:{$broccoli->id}", 'name' => 'Broccoli', 'amount_text' => '200 g', 'is_ticked' => false, 'is_manual' => false],
    ]]]);
});

it('CAP-010 refreshes the list shown, the list with no range and the ranges the ticks are in', function () {
    Carbon::setTestNow('2026-11-09 09:00:00');
    // Another user's ticks come first, so a read of the ticks row that is not the caller's would find them.
    ShoppingTicks::factory()->for(User::factory()->create())->create(['starts_on' => '2026-11-16', 'ends_on' => '2026-11-22']);
    $user = User::factory()->create();
    $item = ManualShoppingItem::factory()->for($user)->create();
    ShoppingTicks::factory()->for($user)->create(['starts_on' => '2026-11-02', 'ends_on' => '2026-11-08', 'keys' => []]);
    $shown = ['from' => '2026-11-09', 'to' => '2026-11-15'];
    $keys = ['shopping_show{}', 'shopping_show{"from":"2026-11-02","to":"2026-11-08"}', 'shopping_show{"from":"2026-11-09","to":"2026-11-15"}'];

    shoppingCall($user, 'shopping_item_add', ['id' => (string) Str::ulid(), 'text' => 'Candles', ...$shown])
        ->assertOk()
        ->assertJsonPath('data.invalidates', $keys);
    shoppingCall($user, 'shopping_item_delete', ['id' => $item->id, ...$shown])
        ->assertOk()
        ->assertJsonPath('data.invalidates', $keys);
    shoppingCall($user, 'shopping_item_add', ['id' => (string) Str::ulid(), 'text' => 'Soap'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['shopping_show{}', 'shopping_show{"from":"2026-11-02","to":"2026-11-08"}']);
    shoppingCall($user, 'shopping_item_add', ['id' => (string) Str::ulid(), 'text' => 'Candles', 'from' => '2026-11-02', 'to' => '2026-11-08'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['shopping_show{}', 'shopping_show{"from":"2026-11-02","to":"2026-11-08"}']);
    // A tick drops the ticks of the range that has ended, so that list changes too.
    shoppingCall($user, 'shopping_item_check', [...$shown, 'key' => 'food:01JBX5F0000000000000000002', 'ticked' => true])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['shopping_show{}', 'shopping_show{"from":"2026-11-09","to":"2026-11-15"}', 'shopping_show{"from":"2026-11-02","to":"2026-11-08"}']);
});

it('SHOP-005 deletes the user\'s manual item and says the list changed', function () {
    $user = User::factory()->create();
    $item = ManualShoppingItem::factory()->for($user)->create();

    shoppingCall($user, 'shopping_item_delete', ['id' => $item->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['shopping_show{}']);

    expect($item->fresh())->toBeNull();
});

it('CAP-019 CAP-020 answers 404 for another user\'s manual item, whichever write names it', function () {
    $user = User::factory()->create();
    $theirs = ManualShoppingItem::factory()->create(['text' => 'Their soap']);

    shoppingCall($user, 'shopping_item_add', ['id' => $theirs->id, 'text' => 'Mine now'])->assertNotFound();
    shoppingCall($user, 'shopping_item_delete', ['id' => $theirs->id])->assertNotFound();
    shoppingCall($user, 'shopping_item_check', ['from' => '2026-11-02', 'to' => '2026-11-08', 'key' => "manual:{$theirs->id}", 'ticked' => true])
        ->assertNotFound();

    expect($theirs->fresh()->text)->toBe('Their soap');
});

it('CAP-012 answers 422 naming the field for one bad input to each shopping capability', function (string $name, array $input, string $field) {
    shoppingCall(User::factory()->create(), $name, $input)
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with([
    'a range that ends before it starts' => ['shopping_show', ['from' => '2026-11-08', 'to' => '2026-11-02'], 'to'],
    'a key that is not a line' => ['shopping_item_check', ['from' => '2026-11-02', 'to' => '2026-11-08', 'key' => 'chicken', 'ticked' => true], 'key'],
    'blank text' => ['shopping_item_add', ['id' => '01JBX5H0000000000000000001', 'text' => '   '], 'text'],
    'no id' => ['shopping_item_delete', [], 'id'],
]);

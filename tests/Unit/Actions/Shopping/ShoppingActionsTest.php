<?php

use App\Actions\Shopping\AddShoppingItem;
use App\Actions\Shopping\CheckShoppingItem;
use App\Actions\Shopping\DeleteShoppingItem;
use App\Domain\Shopping\DateRange;
use App\Models\ManualShoppingItem;
use App\Models\ShoppingTicks;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

// The shopping writes keep the ticks and manual items rp-clone kept on the device (ShoppingListStore),
// so their job is the rows they save: they run on the database. The rules are TickedRangeTest's.
uses(RefreshDatabase::class);

function shopActionRange(string $from, string $to): DateRange
{
    $utc = new DateTimeZone('UTC');

    return new DateRange(new DateTimeImmutable($from, $utc), new DateTimeImmutable($to, $utc));
}

/** @return array{string, string, list<string>}|null the user's ticked range and keys */
function shopActionTicks(User $user): ?array
{
    $ticks = ShoppingTicks::query()->where('user_id', $user->id)->first();

    return $ticks === null ? null : [$ticks->starts_on->format('Y-m-d'), $ticks->ends_on->format('Y-m-d'), $ticks->keys];
}

it('SHOP-005 keeps a tick with the range it was made in, and takes it back', function () {
    $user = User::factory()->create();
    $check = new CheckShoppingItem;

    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);
    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000007', true);
    expect(shopActionTicks($user))->toBe(['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002', 'food:01jbx5f0000000000000000007']]);

    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', false);
    expect(shopActionTicks($user))->toBe(['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000007']]);
});

it('OFF-030 a tick replayed from the outbox lands once', function () {
    $user = User::factory()->create();
    $check = new CheckShoppingItem;

    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);
    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);

    expect(shopActionTicks($user))->toBe(['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002']])
        ->and(ShoppingTicks::query()->count())->toBe(1);
});

it('OFF-030 a manual item added offline can be ticked by the next entry in the outbox', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new AddShoppingItem)($user, null, $id, 'Paper towels');
    (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), "manual:{$id}", true);

    expect(shopActionTicks($user))->toBe(['2026-10-10', '2026-10-16', ["manual:{$id}"]]);
});

it('SHOP-005 a tick in another range clears the old range\'s ticks and says which range that was', function () {
    $user = User::factory()->create();
    $check = new CheckShoppingItem;
    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);

    $same = $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000007', true);
    $untick = $check($user, shopActionRange('2026-10-20', '2026-10-26'), 'food:01jbx5f0000000000000000007', false);
    $old = $check($user, shopActionRange('2026-10-12', '2026-10-14'), 'food:01jbx5f0000000000000000011', true);

    expect($same)->toBeNull()
        ->and($untick)->toBeNull()
        ->and([$old?->lower->format('Y-m-d'), $old?->upper->format('Y-m-d')])->toBe(['2026-10-10', '2026-10-16'])
        ->and(shopActionTicks($user))->toBe(['2026-10-12', '2026-10-14', ['food:01jbx5f0000000000000000011']]);
});

it('SHOP-005 an untick before any tick saves nothing', function () {
    $user = User::factory()->create();

    expect((new CheckShoppingItem)($user, shopActionRange('2026-10-16', '2026-10-22'), 'food:01jbx5f0000000000000000002', false))->toBeNull()
        ->and(shopActionTicks($user))->toBeNull();
});

it('SHOP-005 never ticks another user\'s manual item, or one that is gone', function () {
    $user = User::factory()->create();
    $theirs = ManualShoppingItem::factory()->create();

    expect(fn () => (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), "manual:{$theirs->id}", true))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), 'manual:'.Str::ulid(), true))
        ->toThrow(ModelNotFoundException::class)
        ->and(shopActionTicks($user))->toBeNull();
});

it('SHOP-005 adds a manual item, trimmed, under the id the app made for it', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new AddShoppingItem)($user, null, $id, "   Dish soap \n");

    $item = ManualShoppingItem::query()->findOrFail($id);
    expect($item->user_id)->toBe($user->id)
        ->and($item->text)->toBe('Dish soap');
});

it('SHOP-005 adding the same item again changes nothing but its text', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();
    $item = (new AddShoppingItem)($user, null, $id, 'Dish soap');

    (new AddShoppingItem)($user, $item, $id, 'Washing-up liquid');

    expect(ManualShoppingItem::query()->pluck('text', 'id')->all())->toBe([$id => 'Washing-up liquid']);
});

it('SHOP-005 keeps one ticks row per user', function () {
    $user = User::factory()->create();
    ShoppingTicks::factory()->for($user)->create();

    expect(fn () => ShoppingTicks::factory()->for($user)->create())->toThrow(UniqueConstraintViolationException::class);
});

it('SHOP-005 ticking and deleting change only the caller\'s ticks', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    ShoppingTicks::factory()->for($other)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-16', 'keys' => ['food:01jbx5f0000000000000000002']]);
    $mine = ManualShoppingItem::factory()->for($user)->create();

    (new CheckShoppingItem)($user, shopActionRange('2026-10-12', '2026-10-14'), "manual:{$mine->id}", true);
    (new DeleteShoppingItem)($user, $mine->id);

    expect(shopActionTicks($other))->toBe(['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002']])
        ->and(shopActionTicks($user))->toBe(['2026-10-12', '2026-10-14', []]);
});

it('SHOP-005 a manual item stays until the user deletes it, and its tick goes with it', function () {
    $user = User::factory()->create();
    $soap = ManualShoppingItem::factory()->for($user)->create(['text' => 'Dish soap']);
    $candles = ManualShoppingItem::factory()->for($user)->create(['text' => 'Candles']);
    (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), "manual:{$soap->id}", true);
    (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);

    (new DeleteShoppingItem)($user, $soap->id);

    expect(ManualShoppingItem::query()->pluck('text')->all())->toBe(['Candles'])
        ->and($candles->fresh())->not->toBeNull()
        ->and(shopActionTicks($user))->toBe(['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002']]);
});

it('SHOP-005 never deletes another user\'s manual item', function () {
    $theirs = ManualShoppingItem::factory()->create();

    expect(fn () => (new DeleteShoppingItem)(User::factory()->create(), $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and($theirs->fresh())->not->toBeNull();
});

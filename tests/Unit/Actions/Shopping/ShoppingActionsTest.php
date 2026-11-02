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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// The shopping writes keep the ticks and manual items rp-clone kept on the device (ShoppingListStore),
// so their job is the rows they save: they run on the database. The rules are TickedRangeTest's.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-10 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function shopActionRange(string $from, string $to): DateRange
{
    $utc = new DateTimeZone('UTC');

    return new DateRange(new DateTimeImmutable($from, $utc), new DateTimeImmutable($to, $utc));
}

/** @return list<array{string, string, list<string>}> each of the user's ticked ranges, first day first, with its keys */
function shopActionTicks(User $user): array
{
    return ShoppingTicks::query()->where('user_id', $user->id)->orderBy('starts_on')->orderBy('ends_on')->get()
        ->map(fn (ShoppingTicks $ticks) => [$ticks->starts_on->format('Y-m-d'), $ticks->ends_on->format('Y-m-d'), $ticks->keys])
        ->all();
}

it('SHOP-005 keeps a tick with the range it was made in, and takes it back', function () {
    $user = User::factory()->create();
    $check = new CheckShoppingItem;

    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);
    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000007', true);
    expect(shopActionTicks($user))->toBe([['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002', 'food:01jbx5f0000000000000000007']]]);

    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', false);
    expect(shopActionTicks($user))->toBe([['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000007']]]);
});

it('OFF-030 a tick replayed from the outbox lands once', function () {
    $user = User::factory()->create();
    $check = new CheckShoppingItem;

    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);
    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);

    expect(shopActionTicks($user))->toBe([['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002']]])
        ->and(ShoppingTicks::query()->count())->toBe(1);
});

it('OFF-030 a manual item added offline can be ticked by the next entry in the outbox', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new AddShoppingItem)($user, null, $id, 'Paper towels');
    (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), "manual:{$id}", true);

    expect(shopActionTicks($user))->toBe([['2026-10-10', '2026-10-16', ["manual:{$id}"]]]);
});

it('SHOP-005 OFF-030 a tick in another range keeps that range\'s own ticks, so a stale tick replayed from the outbox wipes nothing', function () {
    $user = User::factory()->create();
    $check = new CheckShoppingItem;
    // This week, ticked on one device.
    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);
    $check($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000007', true);

    // Another device's queued tick for a shorter range, then an untick there, replayed late.
    $check($user, shopActionRange('2026-10-12', '2026-10-14'), 'food:01jbx5f0000000000000000011', true);
    $check($user, shopActionRange('2026-10-12', '2026-10-14'), 'food:01jbx5f0000000000000000011', false);
    $check($user, shopActionRange('2026-10-20', '2026-10-26'), 'food:01jbx5f0000000000000000007', false);

    expect(shopActionTicks($user))->toBe([
        ['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002', 'food:01jbx5f0000000000000000007']],
        ['2026-10-12', '2026-10-14', []],
    ]);
});

it('SHOP-005 a tick drops the ticks of ranges that have ended, and says which', function () {
    $user = User::factory()->create();
    ShoppingTicks::factory()->for($user)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-07']);
    ShoppingTicks::factory()->for($user)->create(['starts_on' => '2026-10-03', 'ends_on' => '2026-10-09']);
    ShoppingTicks::factory()->for($user)->create(['starts_on' => '2026-10-04', 'ends_on' => '2026-10-10']);

    $ended = (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);

    expect(array_map(fn (DateRange $range) => [$range->lower->format('Y-m-d'), $range->upper->format('Y-m-d')], $ended))
        ->toBe([['2026-10-01', '2026-10-07'], ['2026-10-03', '2026-10-09']])
        ->and(array_map(fn (array $row) => [$row[0], $row[1]], shopActionTicks($user)))
        ->toBe([['2026-10-04', '2026-10-10'], ['2026-10-10', '2026-10-16']]);
});

it('SHOP-005 ticks in a range that has ended are kept together, not dropped by the next tick there', function () {
    $user = User::factory()->create();
    $check = new CheckShoppingItem;

    // The screen still shows last week after midnight.
    $check($user, shopActionRange('2026-10-03', '2026-10-09'), 'food:01jbx5f0000000000000000002', true);
    $ended = $check($user, shopActionRange('2026-10-03', '2026-10-09'), 'food:01jbx5f0000000000000000007', true);

    expect($ended)->toBe([])
        ->and(shopActionTicks($user))->toBe([['2026-10-03', '2026-10-09', ['food:01jbx5f0000000000000000002', 'food:01jbx5f0000000000000000007']]]);
});

it('SHOP-005 an untick before any tick saves nothing', function () {
    $user = User::factory()->create();

    expect((new CheckShoppingItem)($user, shopActionRange('2026-10-16', '2026-10-22'), 'food:01jbx5f0000000000000000002', false))->toBe([])
        ->and(shopActionTicks($user))->toBe([]);
});

it('SHOP-005 never ticks another user\'s manual item, or one that is gone', function () {
    $user = User::factory()->create();
    $theirs = ManualShoppingItem::factory()->create();

    expect(fn () => (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), "manual:{$theirs->id}", true))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), 'manual:'.Str::ulid(), true))
        ->toThrow(ModelNotFoundException::class)
        ->and(shopActionTicks($user))->toBe([]);
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

it('SHOP-005 keeps one ticks row per user and range', function () {
    $user = User::factory()->create();
    ShoppingTicks::factory()->for($user)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-16']);
    ShoppingTicks::factory()->for($user)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);

    expect(fn () => ShoppingTicks::factory()->for($user)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-16']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('SHOP-005 ticking and deleting change only the caller\'s ticks', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    ShoppingTicks::factory()->for($other)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-16', 'keys' => ['food:01jbx5f0000000000000000002']]);
    $mine = ManualShoppingItem::factory()->for($user)->create();

    (new CheckShoppingItem)($user, shopActionRange('2026-10-12', '2026-10-14'), "manual:{$mine->id}", true);
    (new DeleteShoppingItem)($user, $mine->id);

    expect(shopActionTicks($other))->toBe([['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002']]])
        ->and(shopActionTicks($user))->toBe([['2026-10-12', '2026-10-14', []]]);
});

it('SHOP-005 a manual item stays until the user deletes it, and its tick goes with it', function () {
    $user = User::factory()->create();
    $soap = ManualShoppingItem::factory()->for($user)->create(['text' => 'Dish soap']);
    $candles = ManualShoppingItem::factory()->for($user)->create(['text' => 'Candles']);
    (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), "manual:{$soap->id}", true);
    (new CheckShoppingItem)($user, shopActionRange('2026-10-10', '2026-10-16'), 'food:01jbx5f0000000000000000002', true);
    (new CheckShoppingItem)($user, shopActionRange('2026-10-11', '2026-10-17'), "manual:{$soap->id}", true);

    (new DeleteShoppingItem)($user, $soap->id);

    expect(ManualShoppingItem::query()->pluck('text')->all())->toBe(['Candles'])
        ->and($candles->fresh())->not->toBeNull()
        ->and(shopActionTicks($user))->toBe([['2026-10-10', '2026-10-16', ['food:01jbx5f0000000000000000002']], ['2026-10-11', '2026-10-17', []]]);
});

it('SHOP-005 never deletes another user\'s manual item', function () {
    $theirs = ManualShoppingItem::factory()->create();

    expect(fn () => (new DeleteShoppingItem)(User::factory()->create(), $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and($theirs->fresh())->not->toBeNull();
});

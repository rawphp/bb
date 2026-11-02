<?php

// Feature, not unit: a lost response is a whole HTTP round trip that the app sends
// again, and only the real route, middleware and idempotency store can show that
// the second pass changes nothing (CAP-014).

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\ClientUlidRow;
use Tests\Support\Fixtures\Rail\ClientUlidRowSaveCapability;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

// Registers test-only capabilities, so each test gets its own application.
uses()->group('warp-isolated');

beforeEach(function () {
    config(['database.connections.client_ulid_rows' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    Schema::connection('client_ulid_rows')->create('client_ulid_rows', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->unsignedBigInteger('user_id');
    });

    $registry = app(CapabilityRegistry::class);
    (new TallyAddCapability)->definition()->register($registry);
    (new ClientUlidRowSaveCapability)->definition()->register($registry);
    TallyAddCapability::$runs = 0;
    TallyAddCapability::$total = 0;

    Carbon::setTestNow('2026-11-02T09:00:00Z');
});

afterEach(function () {
    Carbon::setTestNow();
    DB::purge('client_ulid_rows');
});

/** @return list<array{capability: string, input: array<string, mixed>, headers: array<string, string>}> */
function recordedOutbox(): array
{
    $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/outbox/replay.json')), true, flags: JSON_THROW_ON_ERROR);

    return $fixture['entries'];
}

/** @return list<array<string, mixed>> each entry's answer, in order */
function replayOutbox(): array
{
    return array_map(
        fn (array $entry): array => test()->postJson("/capabilities/{$entry['capability']}", $entry['input'], $entry['headers'])->assertOk()->json(),
        recordedOutbox(),
    );
}

it('OFF-051 replays the same outbox twice and applies each write once', function () {
    $this->actingAs(User::factory()->create());

    $first = replayOutbox();
    // The answers were lost, so the app sends the whole outbox again.
    $second = replayOutbox();

    expect(TallyAddCapability::$runs)->toBe(2)
        ->and(TallyAddCapability::$total)->toBe(6)
        ->and(ClientUlidRow::query()->count())->toBe(1)
        ->and(array_column($second, 'data'))->toEqual(array_column($first, 'data'))
        ->and(array_column(array_column($first, 'meta'), 'idempotent_replay'))->toBe([false, false, false])
        ->and(array_column(array_column($second, 'meta'), 'idempotent_replay'))->toBe([true, true, true]);
});

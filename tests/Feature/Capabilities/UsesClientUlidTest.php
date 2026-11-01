<?php

// Feature, not unit: the ownership check is a real query against a ULID key,
// and the 404 and 422 are the package's mapping of what the trait and the DTO do.
// The users are not saved, so nothing here touches the default connection's data.

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\ClientUlidRow;
use Tests\Support\Fixtures\Rail\ClientUlidRowSaveCapability;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

beforeEach(function () {
    config(['database.connections.client_ulid_rows' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    Schema::connection('client_ulid_rows')->create('client_ulid_rows', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->unsignedBigInteger('user_id');
    });

    (new ClientUlidRowSaveCapability)->definition()->register(app(CapabilityRegistry::class));

    $this->user = tap(new User, fn (User $user) => $user->id = 7);
    $this->other = tap(new User, fn (User $user) => $user->id = 8);
});

afterEach(function () {
    DB::purge('client_ulid_rows');
});

function saveClientUlidRow(string $id): TestResponse
{
    return test()->postJson('/capabilities/client_ulid_row_save', ['id' => $id], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('CAP-019 creates a row under a new client-made ULID', function () {
    $ulid = (string) Str::ulid();

    $this->actingAs($this->user);
    saveClientUlidRow($ulid)->assertOk();

    expect(ClientUlidRow::query()->find($ulid)?->getAttribute('user_id'))->toBe($this->user->id);
});

it('CAP-019 finds the caller\'s own row for a ULID it already created', function () {
    $ulid = (string) Str::ulid();
    ClientUlidRow::create(['id' => $ulid, 'user_id' => $this->user->id]);

    $this->actingAs($this->user);
    saveClientUlidRow($ulid)->assertOk();

    expect(ClientUlidRow::query()->count())->toBe(1);
});

it('CAP-019 answers 404 when another user owns the ULID and leaves the row alone', function () {
    $ulid = (string) Str::ulid();
    ClientUlidRow::create(['id' => $ulid, 'user_id' => $this->other->id]);

    $this->actingAs($this->user);
    saveClientUlidRow($ulid)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'We couldn\'t find that.');

    expect(ClientUlidRow::query()->pluck('user_id', 'id')->all())->toBe([$ulid => $this->other->id]);
});

it('CAP-019 refuses an ID that is not a ULID with a 422 on the field', function () {
    $this->actingAs($this->user);
    saveClientUlidRow('42')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.violations.0.field', 'id');

    expect(ClientUlidRow::query()->count())->toBe(0);
});

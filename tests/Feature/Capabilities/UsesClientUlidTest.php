<?php

// Feature, not unit: the ownership check is a real query against a ULID key.

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Fixtures\Rail\ClientUlidClaimer;
use Tests\Support\Fixtures\Rail\ClientUlidRow;

beforeEach(function () {
    Schema::create('client_ulid_rows', function (Blueprint $table) {
        $table->temporary();
        $table->ulid('id')->primary();
        $table->unsignedBigInteger('user_id');
    });

    $this->user = tap(new User, fn (User $user) => $user->id = 7);
});

afterEach(function () {
    Schema::drop('client_ulid_rows');
});

it('CAP-019 accepts a new client-made ULID for a create', function () {
    expect((new ClientUlidClaimer)->claim((string) Str::ulid(), $this->user))->toBeNull();
});

it('CAP-019 returns the caller\'s own row for a ULID it already created', function () {
    $ulid = (string) Str::ulid();
    ClientUlidRow::create(['id' => $ulid, 'user_id' => 7]);

    expect((new ClientUlidClaimer)->claim($ulid, $this->user)?->getKey())->toBe($ulid);
});

it('CAP-019 answers not found when another user owns the ULID', function () {
    $ulid = (string) Str::ulid();
    ClientUlidRow::create(['id' => $ulid, 'user_id' => 8]);

    (new ClientUlidClaimer)->claim($ulid, $this->user);
})->throws(ModelNotFoundException::class);

it('CAP-019 refuses an ID that is not a ULID', function () {
    (new ClientUlidClaimer)->claim('42', $this->user);
})->throws(InvalidArgumentException::class, 'That ID is not valid.');

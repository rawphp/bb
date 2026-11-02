<?php

use App\Actions\WeighIns\DeleteWeighIn;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/WeighIns/WeighInsModelTests.swift (delete).
uses(RefreshDatabase::class);

it('WT-004 deletes a weigh-in and names its day', function () {
    $user = User::factory()->create();
    $weighIn = WeighIn::factory()->for($user)->create(['weighed_at' => '2026-10-06 08:00:00']);
    $kept = WeighIn::factory()->for($user)->create();

    expect((new DeleteWeighIn)($user, $weighIn->id))->toBe('2026-10-06')
        ->and(WeighIn::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('WT-004 never deletes another user\'s weigh-in', function () {
    $weighIn = WeighIn::factory()->create();

    expect(fn () => (new DeleteWeighIn)(User::factory()->create(), $weighIn->id))->toThrow(ModelNotFoundException::class)
        ->and($weighIn->fresh())->not->toBeNull();
});

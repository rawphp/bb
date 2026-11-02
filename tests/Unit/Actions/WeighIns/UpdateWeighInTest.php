<?php

use App\Actions\WeighIns\UpdateWeighIn;
use App\Capabilities\WeighIns\Data\WaistInput;
use App\Capabilities\WeighIns\Data\WeightInput;
use App\Enums\MeasurementSource;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/WeighIns/WeighInEntryModelTests.swift (edit).
uses(RefreshDatabase::class);

it('WT-004 changes a weigh-in\'s time, weight and waist, keeps its source and names both days', function () {
    $user = User::factory()->create();
    $weighIn = WeighIn::factory()->for($user)->shortcut()->create(['weight_kg' => 81.4, 'weighed_at' => '2026-10-08 06:00:00']);

    $days = (new UpdateWeighIn)($user, $weighIn->id, '2026-10-09T07:30:00+00:00', new WeightInput(200, 'lb'), new WaistInput(86, 'cm'));

    expect($days)->toBe(['2026-10-08', '2026-10-09'])
        ->and($weighIn->fresh())
        ->weight_kg->toBe(90.72)
        ->waist_cm->toBe(86.0)
        ->source->toBe(MeasurementSource::Shortcut)
        ->and($weighIn->fresh()->weighed_at->format('Y-m-d H:i'))->toBe('2026-10-09 07:30');
});

it('WT-021 an edit without a waist clears it', function () {
    $user = User::factory()->create();
    $weighIn = WeighIn::factory()->for($user)->create(['waist_cm' => 86, 'weighed_at' => '2026-10-08 06:00:00']);

    $days = (new UpdateWeighIn)($user, $weighIn->id, '2026-10-08T06:00:00+00:00', new WeightInput(81, 'kg'), null);

    expect($days)->toBe(['2026-10-08'])
        ->and($weighIn->fresh()->waist_cm)->toBeNull();
});

it('WT-002 refuses an edit above 999.99 kg, or to a time the calendar does not have, and keeps the reading', function () {
    $user = User::factory()->create();
    $weighIn = WeighIn::factory()->for($user)->create(['weight_kg' => 81.4, 'weighed_at' => '2026-10-08 06:00:00']);

    expect(fn () => (new UpdateWeighIn)($user, $weighIn->id, '2026-10-08T06:00:00+00:00', new WeightInput(1000, 'kg'), null))
        ->toThrow(DomainException::class, 'Enter a weight above 0 and up to 999.99 kg.')
        ->and(fn () => (new UpdateWeighIn)($user, $weighIn->id, '2026-02-30T06:00:00+00:00', new WeightInput(81, 'kg'), null))
        ->toThrow(DomainException::class, 'Enter a real date and time.')
        ->and($weighIn->fresh())->weight_kg->toBe(81.4)
        ->and($weighIn->fresh()->weighed_at->format('Y-m-d H:i'))->toBe('2026-10-08 06:00');
});

it('WT-004 never edits another user\'s weigh-in', function () {
    $weighIn = WeighIn::factory()->create(['weight_kg' => 81.4]);

    expect(fn () => (new UpdateWeighIn)(User::factory()->create(), $weighIn->id, '2026-10-08T06:00:00+00:00', new WeightInput(70, 'kg'), null))
        ->toThrow(ModelNotFoundException::class)
        ->and($weighIn->fresh()->weight_kg)->toBe(81.4);
});

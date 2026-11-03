<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Agent\Coach;

// The eval set's harness (AGT-050) fails a case for each way a Coach can get it wrong, on the fake driver.
uses()->group('warp-isolated');

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('AGT-050 the eval harness fails a case whose expected call the app refuses', function () {
    $user = User::factory()->create();

    expect(fn () => Coach::says($user, 'I walked 9,000 steps today', calls: [['steps_log', ['date' => 'today', 'steps' => 9000]]]))
        ->toThrow(ExpectationFailedException::class, 'The Coach should call steps_log');
});

it('AGT-050 the eval harness fails a case in which the Coach changes something it was not asked to', function () {
    $user = User::factory()->create();

    // The fake model tries a refused call too; one the app accepts is a write nobody expected.
    expect(fn () => Coach::says($user, 'How many steps did I walk?', refused: [['steps_log', ['date' => '2026-09-23', 'steps' => 9000]]]))
        ->toThrow(ExpectationFailedException::class, 'The Coach changed something it was not asked to: steps_log');
});

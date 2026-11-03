<?php

use App\Actions\Plan\SetPhaseEventDate;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PlanSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function setEvent(User $user, string $phaseId, ?string $date): array|string
{
    try {
        return app(SetPhaseEventDate::class)($user, $phaseId, $date);
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

it('PH-043 a phase takes an event date, and null clears it', function () {
    [$user] = PlanSeed::world();

    expect(setEvent($user, W::HOLD, '2026-12-19'))->toBe([W::HOLD])
        ->and(Phase::query()->findOrFail(W::HOLD)->event_date->format('Y-m-d'))->toBe('2026-12-19');

    setEvent($user, W::HOLD, null);
    expect(Phase::query()->findOrFail(W::HOLD)->event_date)->toBeNull();
});

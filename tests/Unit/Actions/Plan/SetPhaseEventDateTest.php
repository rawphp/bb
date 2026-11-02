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

it('PH-043 an event cannot be set in the past or on a phase that is over', function () {
    [$user] = PlanSeed::world();

    expect(setEvent($user, W::CUT, '2026-10-13'))->toBe('An event date cannot be in the past.');

    Carbon::setTestNow('2026-12-01 10:00:00');
    expect(setEvent($user, W::CUT, '2026-12-19'))->toBe('This phase is over, so its event date can no longer change.')
        ->and(Phase::query()->findOrFail(W::CUT)->event_date)->toBeNull();
});

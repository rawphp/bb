<?php

use App\Models\PlanWeek;
use Illuminate\Support\Carbon;
use Tests\Agent\Coach;
use Tests\Support\PlanSeed;

// Eval requests about the plan beyond this week (AGT-050). Today is Wednesday 14 October 2026.
uses()->group('warp-isolated');

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('AGT-050 "keep me in a deficit in my November deload"', function () {
    [$user] = PlanSeed::world();

    Coach::says($user, 'Keep me in a deficit in my November deload', calls: [
        ['plan_diet_break_set', ['week_start' => '2026-11-16', 'diet_break' => false]],
    ], reply: 'The deload week from 16 November now stays in a deficit.');

    expect(PlanWeek::query()->where('user_id', $user->id)->sole()->diet_break_off)->toBeTrue();
});

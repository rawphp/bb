<?php

use App\Capabilities\Today\Data\AwayWeekSetInput;

// PLAN-025, the #99 f1 fix: away_week_set without keep_phase_end leaves each week's saved value, so the key left out
// hydrates as null, never as false. What null then does is SetAwayWeeksTest's (b) and (d) and AwayWeekSetTest's seam test.

it('PLAN-025 away_week_set reads a missing keep_phase_end as null, and a sent one as sent', function () {
    expect(AwayWeekSetInput::fromArray(['week_starts' => ['2026-09-26'], 'away' => true])->keep_phase_end)->toBeNull()
        ->and(AwayWeekSetInput::fromArray(['week_starts' => ['2026-09-26'], 'away' => true, 'keep_phase_end' => false])->keep_phase_end)->toBeFalse()
        ->and(AwayWeekSetInput::fromArray(['week_starts' => ['2026-09-26'], 'away' => true, 'keep_phase_end' => true])->keep_phase_end)->toBeTrue();
});

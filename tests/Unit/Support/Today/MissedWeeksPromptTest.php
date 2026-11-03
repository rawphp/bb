<?php

use App\Capabilities\Reads\Today\TodayPayload;
use App\Models\WorkoutSession;
use App\Support\Today\MissedWeeksPrompt;
use Tests\Unit\Capabilities\Reads\Today\TodayWorld as W;

// SCH-058, rp-clone TodayMissedWeeksTests: the question Today asks after a plan week or more without training. The
// world: plan weeks start Saturday 12 September (week 0), Meso A has 4 weeks of 3 sessions on Mon, Wed and Fri from
// the plan start. Plan week 1 starts 19 Sep, week 2 on 26 Sep, week 3 on 3 Oct.

function missedPrompt(string $today, array $sessions = [], array $planWeeks = [], ?array $mesocycles = null): ?MissedWeeksPrompt
{
    return MissedWeeksPrompt::from(W::snapshot(today: $today, mesocycles: $mesocycles, planWeeks: $planWeeks, sessions: $sessions));
}

/** A started and finished session of Meso A. */
function startedSession(int $week, int $day, string $date): WorkoutSession
{
    return W::session("s{$week}{$day}", $week, $day, "{$date} 17:30:00", "{$date} 18:30:00");
}

function skippedSession(int $week, int $day): WorkoutSession
{
    return W::session("k{$week}{$day}", $week, $day, skipped: true);
}

it('SCH-058 asks after a whole plan week in which nothing was started', function () {
    $prompt = missedPrompt('2026-09-30');

    expect($prompt)->not->toBeNull()
        ->and($prompt->mesocycle->id)->toBe(W::MESO)
        ->and($prompt->stoppedWeek)->toBe(1)
        ->and($prompt->canRestart())->toBeTrue()
        ->and($prompt->shift)->toBe(2)
        ->and($prompt->restartStart->format('Y-m-d'))->toBe('2026-09-26');
});

it('SCH-058 does not ask while the week after the last session is still in progress', function () {
    expect(missedPrompt('2026-09-23', [startedSession(1, 1, '2026-09-14')]))->toBeNull()
        // The week itself is not a whole week yet, whatever happened in the one before.
        ->and(missedPrompt('2026-09-30', [startedSession(1, 1, '2026-09-14'), startedSession(2, 1, '2026-09-21')]))->toBeNull();
});

it('SCH-058 asks when a week after the last trained one has ended with nothing started', function () {
    $prompt = missedPrompt('2026-09-30', [startedSession(1, 1, '2026-09-14')]);

    expect($prompt)->not->toBeNull()
        // The rest of the trained week was left unlogged: that is where the user stopped.
        ->and($prompt->stoppedWeek)->toBe(1);
});

it('SCH-058 a skipped session counts as not started, and the stopped week is the first one neither started nor skipped', function () {
    $prompt = missedPrompt('2026-09-30', [skippedSession(1, 1), skippedSession(1, 2), skippedSession(1, 3)]);

    expect($prompt->stoppedWeek)->toBe(2)
        ->and($prompt->shift)->toBe(1)
        ->and($prompt->restartStart->format('Y-m-d'))->toBe('2026-09-19')
        ->and($prompt->text())->toBe("You haven't trained since week 1 of Meso A. Restart week 2, or end this mesocycle and build a new one.");
});

it('SCH-058 a session left unlogged before the last trained week does not pull the restart back', function () {
    $prompt = missedPrompt('2026-10-07', [startedSession(2, 1, '2026-09-21')]);

    // Week 1's three sessions were never touched, but the user trained in week 2: they stopped in week 2.
    expect($prompt->stoppedWeek)->toBe(2)
        ->and($prompt->shift)->toBe(2);
});

it('SCH-058 a paused away week never asks', function () {
    $sessions = [startedSession(1, 1, '2026-09-14'), startedSession(1, 2, '2026-09-16'), startedSession(1, 3, '2026-09-18')];
    $paused = [W::planWeek('2026-09-19', away: true, awayTraining: 'pause')];

    expect(missedPrompt('2026-09-28', $sessions, $paused))->toBeNull();
});

it('SCH-058 an ended mesocycle is not asked about', function () {
    expect(missedPrompt('2026-09-30', mesocycles: [W::mesocycle(['ended_on' => '2026-09-20'])]))->toBeNull();
});

it('SCH-058 a mesocycle with another saved after it is not asked about', function () {
    $next = W::mesocycle(['id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'name' => 'Meso B', 'start_date' => '2026-10-10']);

    expect(missedPrompt('2026-09-30', mesocycles: [W::mesocycle(), $next]))->toBeNull();
});

it('SCH-058 weeks skipped right up to this one ask but offer no restart', function () {
    $sessions = [
        startedSession(1, 1, '2026-09-14'), startedSession(1, 2, '2026-09-16'), startedSession(1, 3, '2026-09-18'),
        skippedSession(2, 1), skippedSession(2, 2), skippedSession(2, 3),
    ];
    $prompt = missedPrompt('2026-09-30', $sessions);

    expect($prompt)->not->toBeNull()
        ->and($prompt->stoppedWeek)->toBe(3)
        ->and($prompt->shift)->toBe(0)
        ->and($prompt->canRestart())->toBeFalse()
        ->and($prompt->text())->toBe("You haven't trained since week 2 of Meso A. End this mesocycle and build a new one.");
});

it('SCH-058 says nothing has been trained yet when the stopped week is the first', function () {
    expect(missedPrompt('2026-09-30')->text())->toBe("You haven't trained in Meso A yet. Restart week 1, or end this mesocycle and build a new one.");
});

it('SCH-058 names a mesocycle without a name Mesocycle', function () {
    expect(missedPrompt('2026-09-30', mesocycles: [W::mesocycle(['name' => null])])->text())->toContain('You haven\'t trained in Mesocycle yet.');
});

it('SCH-058 today_show carries the prompt in the shape of its fixture, and null when nothing is due', function () {
    $payload = TodayPayload::build(W::snapshot(today: '2026-09-30', sessions: [skippedSession(1, 1), skippedSession(1, 2), skippedSession(1, 3)]))->toArray();

    expect($payload['missed_weeks'])->toBe([
        'mesocycle_id' => W::MESO,
        'title' => 'Welcome back',
        'text' => "You haven't trained since week 1 of Meso A. Restart week 2, or end this mesocycle and build a new one.",
        'can_restart' => true,
        'end_warning' => 'Ending a mesocycle keeps its logged workouts but drops the weeks still to come.',
        'offers_diet_break' => false,
    ])->and(TodayPayload::build(W::snapshot())->toArray()['missed_weeks'])->toBeNull();
});

it('SCH-058 today_show carries the prompt on today only: any other date gets null', function () {
    $skipped = [skippedSession(1, 1), skippedSession(1, 2), skippedSession(1, 3)];
    $onToday = TodayPayload::build(W::snapshot(today: '2026-09-30', date: '2026-09-30', sessions: $skipped))->toArray();
    $elsewhere = fn (string $date) => TodayPayload::build(W::snapshot(today: '2026-09-30', date: $date, sessions: $skipped))->toArray()['missed_weeks'];

    expect($onToday['missed_weeks'])->not->toBeNull()
        ->and($elsewhere('2026-09-20'))->toBeNull()
        ->and($elsewhere('2026-10-01'))->toBeNull()
        // Another day of today's week.
        ->and($elsewhere('2026-09-28'))->toBeNull();
});

it('SCH-058 the prompt is about today, whatever date the snapshot was loaded for', function () {
    $today = MissedWeeksPrompt::from(W::snapshot(today: '2026-09-30', sessions: []));
    $other = MissedWeeksPrompt::from(W::snapshot(today: '2026-09-30', date: '2026-09-20', sessions: []));

    expect($other->text())->toBe($today->text())
        ->and([$other->stoppedWeek, $other->shift])->toBe([$today->stoppedWeek, $today->shift]);
});

it('SCH-058 MESO-044 offers a diet break when a Fat loss phase covers the mesocycle, and not otherwise', function (string $type, bool $offered) {
    $phase = W::phase($type);
    $snapshot = W::snapshot(today: '2026-09-30', mesocycles: [W::mesocycle(['phase_id' => $phase->id])], sessions: [], phases: [$phase]);

    expect(MissedWeeksPrompt::from($snapshot)->offersDietBreak)->toBe($offered)
        ->and(TodayPayload::build($snapshot)->toArray()['missed_weeks']['offers_diet_break'])->toBe($offered);
})->with([
    'Fat loss' => ['fatLoss', true],
    'Muscle gain' => ['muscleGain', false],
]);

it('SCH-058 MESO-044 offers no diet break to a mesocycle without a phase', function () {
    expect(missedPrompt('2026-09-30')->offersDietBreak)->toBeFalse();
});

it('SCH-058 SCH-060 a paused away week inside the mesocycle moves the restart one plan week less', function () {
    // Meso A is on plan weeks 0, 2, 3 and 4 with week 1 paused. Week 0's sessions were skipped, so the user stopped in
    // its week 2, which falls on plan week 2. Today is in plan week 3: starting one plan week later puts mesocycle week
    // 2 on plan week 3, where a mutant that ignores the pause needs two.
    $paused = [W::planWeek('2026-09-19', away: true, awayTraining: 'pause')];
    $prompt = missedPrompt('2026-10-07', [skippedSession(1, 1), skippedSession(1, 2), skippedSession(1, 3)], $paused);

    expect($prompt->stoppedWeek)->toBe(2)
        ->and($prompt->paused)->toBe([1])
        ->and($prompt->shift)->toBe(1)
        ->and($prompt->restartStart->format('Y-m-d'))->toBe('2026-09-19');
});

// The last started week is the plan week of the session's start as stored: a wall-clock day read as UTC, like every day
// the app reads (TrainSnapshot::day; config/app.php pins UTC and there is no per-user time zone yet,
// docs/architecture.md). Plan week 1 ends on Friday 25 September and week 2 starts on Saturday 26th; today is the first
// day of week 3.

it('SCH-058 a session started at 23:30 on the last day of a plan week counts for that week, so the whole week after it asks', function () {
    $prompt = missedPrompt('2026-10-03', [W::session('late', 2, 3, '2026-09-25 23:30:00', '2026-09-26 00:30:00')]);

    // Week 2 (26 Sep to 2 Oct) ended with nothing started; the user stopped in meso week 2, whose first two sessions
    // were never logged.
    expect($prompt)->not->toBeNull()
        ->and($prompt->stoppedWeek)->toBe(2)
        ->and($prompt->shift)->toBe(2);
});

it('SCH-058 a session started at 00:30 on the first day of the next plan week counts for that week, so nothing asks yet', function () {
    expect(missedPrompt('2026-10-03', [W::session('late', 2, 3, '2026-09-26 00:30:00', '2026-09-26 01:30:00')]))->toBeNull()
        // A whole week later, the week after it has ended with nothing started.
        ->and(missedPrompt('2026-10-10', [W::session('late', 2, 3, '2026-09-26 00:30:00', '2026-09-26 01:30:00')])?->stoppedWeek)->toBe(3);
});

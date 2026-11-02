<?php

use App\Capabilities\Reads\Train\MesocyclePayload;
use App\Models\Mesocycle;
use App\Support\Mesocycles\TrainSnapshot;
use Tests\Unit\Capabilities\Reads\Train\TrainWorld as W;

// One mesocycle (rp-clone MesocycleViewModel, +Days, +End, ProgressionRatesModel and MesocycleBuildModel), from
// in-memory rows. Today is Tuesday 6 October, week 4 of "Meso 2", unless a test says otherwise.

function shown(TrainSnapshot $snapshot, ?Mesocycle $mesocycle = null): array
{
    return MesocyclePayload::build($snapshot, $mesocycle ?? $snapshot->mesocycles->first())->toArray();
}

function saved(string $today = '2026-10-06', array $meso = [], array $world = []): array
{
    return shown(W::snapshot(...['today' => $today, 'mesocycles' => [W::mesocycle($meso)], ...$world]))['saved'];
}

/** @return array<string, int> sets per muscle title in a week */
function weekSets(array $saved, int $week): array
{
    return array_column($saved['weeks'][$week - 1]['muscle_sets'], 'sets', 'muscle_title');
}

/** The fat loss world: the same mesocycle under a fat loss phase. */
function cutWorld(): array
{
    return ['phases' => [W::phase(W::CUT, 'fatLoss', '2026-07-27', null)]];
}

function cutSaved(string $today = '2026-10-06', array $world = [], string $type = 'fatLoss'): array
{
    return saved($today, ['phase_id' => W::CUT], [...cutWorld(), 'phases' => [W::phase(W::CUT, $type, '2026-07-27', null)], ...$world]);
}

it('MESO-040 shows a column per week with the deload last, and the name and phase', function () {
    $payload = shown(W::snapshot());

    expect($payload['name'])->toBe('Meso 2')
        ->and($payload['build'])->toBeNull()
        ->and($payload['saved']['phase_title'])->toBe('Muscle gain')
        ->and($payload['saved']['weeks_count'])->toBe(5)
        ->and(array_column($payload['saved']['weeks'], 'number'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($payload['saved']['weeks'], 'title'))->toBe(['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Deload'])
        ->and(array_column($payload['saved']['weeks'], 'now'))->toBe([null, null, null, ['text' => 'Now', 'tone' => 'info'], null]);
});

it('MESO-040 each week lists each day\'s exercises, and the deload halves them and drops traps', function () {
    $saved = saved();
    $week4 = $saved['weeks'][3];
    $deload = $saved['weeks'][4];

    expect(array_column($week4['days'], 'label'))->toBe(['Upper A', 'Lower A'])
        ->and(array_column($week4['days'], 'title'))->toBe(['Day 1 · Upper A', 'Day 2 · Lower A'])
        ->and(array_column($week4['days'][0]['exercises'], 'name'))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and(array_column($week4['days'][0]['exercises'], 'sets'))->toBe([3, 3, 2])
        ->and(array_column($week4['days'][0]['exercises'], 'slot_id'))->toBe(['ua-1', 'ua-2', 'ua-3'])
        ->and(array_column($week4['days'][1]['exercises'], 'name'))->toBe(['Back Squat', 'Barbell Shrug'])
        ->and(array_column($deload['days'][0]['exercises'], 'sets'))->toBe([1, 1, 1])
        ->and(array_column($deload['days'][1]['exercises'], 'name'))->toBe(['Back Squat'])
        ->and(array_column($deload['days'][1]['exercises'], 'sets'))->toBe([1]);
});

it('MESO-040 trained weeks count logged sets and weeks ahead count the plan', function () {
    $saved = saved();

    expect(weekSets($saved, 1))->toBe([])
        ->and(weekSets($saved, 2))->toBe(['Chest' => 3, 'Back' => 3, 'Biceps' => 2])
        ->and(weekSets($saved, 3))->toBe([])
        ->and(weekSets($saved, 4))->toBe(['Chest' => 3, 'Quads' => 3, 'Biceps' => 2, 'Traps' => 2])
        ->and(weekSets($saved, 5))->toBe(['Chest' => 1, 'Back' => 1, 'Quads' => 1, 'Biceps' => 1]);
});

it('MESO-040 a day is done, started, skipped, missed or upcoming', function () {
    $saved = saved(world: ['sessions' => [
        ...W::sessions(),
        W::session('w3d1', 3, 1, skipped: true),
        W::session('w3d2', 3, 2, '2026-10-01 17:30:00'),
        // SCH-051: a row that only moved the session is not started.
        W::session('w4d2', 4, 2),
    ]]);

    expect(array_map(fn ($week) => array_column($week['days'], 'state'), $saved['weeks']))->toBe([
        ['missed', 'missed'],
        ['done', 'missed'],
        ['skipped', 'started'],
        ['done', 'upcoming'],
        ['upcoming', 'upcoming'],
    ])
        ->and(array_unique(array_merge(...array_map(fn ($week) => array_column($week['days'], 'can_open'), $saved['weeks']))))->toBe([true]);
});

it('MESO-041 each week shows its mean daily calorie target, or none without targets', function () {
    expect(array_column(saved()['weeks'], 'calories_text'))->toBe([null, '2,300 kcal/day', null, '2,000 kcal/day', null]);
});

it('MESO-041 a flagged week is a diet break, but a muscle gain deload is not', function () {
    expect(array_column(saved()['weeks'], 'diet_break'))
        ->toBe([null, null, ['text' => 'Diet break', 'tone' => 'info'], null, null]);
});

it('MESO-041 PLAN-022 a fat loss deload is a diet break unless it was turned off', function () {
    $cut = array_map(fn ($pill) => $pill !== null, array_column(cutSaved()['weeks'], 'diet_break'));
    $off = array_map(fn ($pill) => $pill !== null, array_column(cutSaved(world: ['planWeeks' => [
        W::planWeek('2026-10-12', deload: true, dietBreakOff: true),
    ]])['weeks'], 'diet_break'));

    expect($cut)->toBe([false, false, true, false, true])
        ->and($off)->toBe([false, false, false, false, false]);
});

it('MESO-041 PLAN-002 a week\'s diet break follows the phase covering that week, not the mesocycle\'s phase', function () {
    // The mesocycle belongs to muscle gain, but fat loss covers its deload week.
    $saved = saved(world: ['phases' => [
        W::phase(W::GAIN, 'muscleGain', '2026-07-27', '2026-10-12'),
        W::phase(W::CUT, 'fatLoss', '2026-10-12', null),
    ]]);

    expect($saved['phase_title'])->toBe('Muscle gain')
        ->and($saved['weeks'][4]['diet_break'])->not->toBeNull();
});

it('MESO-041 PLAN-002 a deload past the last phase\'s end follows the mesocycle\'s own phase', function () {
    // Fat loss ends on 12 October, as the deload week starts, and no phase follows it.
    $saved = saved(meso: ['phase_id' => W::CUT], world: ['phases' => [W::phase(W::CUT, 'fatLoss', '2026-07-27', '2026-10-12')], 'planWeeks' => []]);

    expect($saved['weeks'][4]['diet_break'])->toBe(['text' => 'Diet break', 'tone' => 'info']);
});

it('MESO-042 the length runs from the current week plus one to eight', function () {
    $week2 = saved('2026-09-23');

    expect($week2['length_text'])->toBe('5 weeks · week 2 now')
        ->and($week2['can_shorten'])->toBeTrue()
        ->and($week2['can_lengthen'])->toBeTrue();
});

it('MESO-042 shortening stops at the current week plus one, lengthening at eight', function () {
    expect(saved()['can_shorten'])->toBeFalse()
        ->and(saved()['can_lengthen'])->toBeTrue()
        ->and(saved(meso: ['weeks' => 8])['can_lengthen'])->toBeFalse()
        ->and(saved(meso: ['weeks' => 8])['can_shorten'])->toBeTrue();
});

it('MESO-042 it cannot lengthen into the next saved mesocycle', function () {
    $next = W::mesocycle(['id' => 'next', 'name' => 'Meso 3', 'start_date' => '2026-10-19']);
    $payload = shown(W::snapshot(today: '2026-09-23', mesocycles: [W::mesocycle(), $next]), W::mesocycle());

    expect($payload['saved']['can_lengthen'])->toBeFalse()
        ->and($payload['saved']['can_shorten'])->toBeTrue();
});

it('MESO-042 neither an earlier mesocycle nor a copy waiting for the next start caps the length', function () {
    $earlier = W::mesocycle(['id' => 'earlier', 'name' => 'Meso 1', 'start_date' => '2026-07-27']);
    $copy = W::mesocycle(['id' => 'copy', 'name' => null, 'is_draft' => true, 'route' => 'copy_previous', 'start_date' => '2026-10-19']);
    $snapshot = W::snapshot(today: '2026-09-23', mesocycles: [$earlier, W::mesocycle(), $copy]);
    $range = $snapshot->calendar($snapshot->mesocycle(W::MESO))->lengthRange();

    expect(shown($snapshot, $snapshot->mesocycle(W::MESO))['saved']['can_lengthen'])->toBeTrue()
        ->and([$range->lower, $range->upper])->toBe([3, 8]);
});

it('MESO-042 both are off during the deload week and once it is over', function () {
    $deload = saved('2026-10-13');
    $over = saved('2026-11-30');

    expect([$deload['can_shorten'], $deload['can_lengthen']])->toBe([false, false])
        ->and([$over['can_shorten'], $over['can_lengthen']])->toBe([false, false])
        ->and($over['length_text'])->toBe('5 weeks')
        ->and(array_column($over['weeks'], 'now'))->toBe([null, null, null, null, null]);
});

it('MESO-042 SCH-039 the length cannot change while later weeks are stored, and the columns follow them', function () {
    $listed = saved('2026-09-23', meso: [
        'earlier_weeks' => [[W::days()[1]]],
        'later_weeks' => [['upper-a'], ['upper-a'], ['upper-a']],
    ]);

    expect([$listed['can_shorten'], $listed['can_lengthen']])->toBe([false, false])
        ->and(array_map(fn ($week) => array_column($week['days'], 'label'), $listed['weeks']))
        ->toBe([['Lower A'], ['Upper A'], ['Upper A'], ['Upper A'], ['Upper A', 'Lower A']]);
});

it('MESO-043 a running mesocycle offers to end, warning that skipping the deload is not recommended', function () {
    expect(saved()['end'])->toBe([
        'warning' => 'Skipping the deload is not recommended for intermediate and advanced lifters.',
        'diet_break_offer' => null,
    ])
        ->and(saved('2026-09-14')['end'])->not->toBeNull()
        ->and(saved('2026-09-10')['end'])->toBeNull()
        ->and(saved('2026-11-30')['end'])->toBeNull();
});

it('MESO-043 once ended the weeks stay and the days after the end are not planned', function () {
    $saved = saved('2026-10-06', meso: ['ended_on' => '2026-10-06']);

    expect($saved['weeks_count'])->toBe(5)
        ->and(array_column($saved['weeks'][3]['days'], 'state'))->toBe(['done', 'not_planned'])
        ->and(array_column($saved['weeks'][3]['days'], 'can_open'))->toBe([true, false])
        ->and(array_column($saved['weeks'][4]['days'], 'state'))->toBe(['not_planned', 'not_planned'])
        ->and(array_column($saved['weeks'][3]['muscle_sets'], 'muscle_title'))->toBe(['Chest', 'Biceps'])
        ->and($saved['weeks'][4]['muscle_sets'])->toBe([])
        ->and($saved['end'])->toBeNull()
        ->and($saved['can_add_day'])->toBeFalse();
});

it('MESO-044 ending early during fat loss offers a diet break next week', function () {
    expect(cutSaved()['end']['diet_break_offer'])->toBe('You are in fat loss. Start a diet break next week?')
        ->and(cutSaved(type: 'miniCut')['end']['diet_break_offer'])->not->toBeNull()
        ->and(saved()['end']['diet_break_offer'])->toBeNull();
});

it('MESO-044 no offer when the current week is already a diet break', function () {
    // The week from 28 September is a diet break.
    expect(cutSaved('2026-09-30')['end']['diet_break_offer'])->toBeNull();
});

it('MESO-045 days can be added while the mesocycle runs', function () {
    // The user trains Monday, Thursday and Friday; the mesocycle's days are on Monday and Thursday.
    $friday = ['trainingDays' => [1, 4, 5]];

    expect(saved(world: $friday)['can_add_day'])->toBeTrue()
        ->and(saved('2026-11-30', world: $friday)['can_add_day'])->toBeFalse();
});

it('MESO-045 a weekday split with every training day taken can still add a day, as can a floating one', function () {
    $floating = array_map(fn (array $day) => array_replace($day, ['weekday' => null]), W::days());

    expect(saved()['can_add_day'])->toBeTrue()
        ->and(saved(meso: ['days' => $floating])['can_add_day'])->toBeTrue();
});

it('MESO-005 MESO-045 a "Day 3" added to a full weekday split does not take Day 1\'s date: it is missed once its week is past, else upcoming', function () {
    // Monday and Thursday are taken, so Day 3 has no date. On Tuesday 6 October, Monday 5 October's Day 1 is done.
    $saved = saved(meso: ['days' => [...W::days(), ['id' => 'day-3', 'label' => 'Day 3', 'weekday' => null, 'slots' => []]]]);

    expect(array_column($saved['weeks'][1]['days'], 'state'))->toBe(['done', 'missed', 'missed'])
        ->and(array_column($saved['weeks'][3]['days'], 'state'))->toBe(['done', 'upcoming', 'upcoming'])
        ->and(array_column($saved['weeks'][4]['days'], 'state'))->toBe(['upcoming', 'upcoming', 'upcoming']);
});

it('MESO-046 a day lists its exercises in the order the mesocycle plans them', function () {
    $reordered = W::days();
    $reordered[0]['slots'] = array_reverse($reordered[0]['slots']);

    expect(array_column(saved(meso: ['days' => $reordered])['weeks'][0]['days'][0]['exercises'], 'name'))
        ->toBe(['Dumbbell Curl', 'Barbell Row', 'Bench Press']);
});

it('MESO-030 MESO-034 lists all fourteen muscles with bars; a muscle left out is Maintain and a null is Off', function () {
    $payload = shown(W::snapshot());
    $rows = collect($payload['saved']['priorities'])->keyBy('muscle');

    expect($payload['saved']['priorities'])->toHaveCount(14)
        ->and($rows['chest'])->toBe(['muscle' => 'chest', 'muscle_title' => 'Chest', 'priority' => 'emphasize', 'priority_title' => 'Emphasize', 'bars' => 3])
        ->and($rows['quads']['priority'])->toBe('maintain')
        ->and($rows['quads']['bars'])->toBe(1)
        ->and($rows['forearms'])->toMatchArray(['priority' => null, 'priority_title' => 'Off', 'bars' => 0])
        ->and(array_column($payload['priority_options'], 'title'))->toBe(['Emphasize', 'Grow', 'Maintain', 'Off']);
});

it('MESO-040 day ids missing from an old row are given from their position', function () {
    $days = array_map(fn (array $day) => ['label' => $day['label'], 'weekday' => $day['weekday'], 'slots' => array_map(
        fn (array $slot) => array_diff_key($slot, ['id' => true]),
        $day['slots'],
    )], W::days());

    expect(array_column(saved(meso: ['days' => $days])['weeks'][0]['days'][0]['exercises'], 'slot_id'))
        ->toBe(['day-1-slot-1', 'day-1-slot-2', 'day-1-slot-3']);
});

// MARK: a draft

function draftOf(array $meso = [], array $world = []): array
{
    $draft = W::mesocycle(array_replace([
        'id' => 'draft', 'name' => null, 'is_draft' => true, 'start_date' => null, 'weeks' => null,
        'phase_id' => null, 'route' => 'builder', 'days' => [], 'muscle_priorities' => [],
    ], $meso));

    return shown(W::snapshot(...['mesocycles' => [W::mesocycle(), $draft], ...$world]), $draft);
}

it('MESO-007 a draft shows the create flow where it was left', function () {
    $empty = draftOf();
    $withDays = draftOf(['days' => W::days(), 'route' => 'template']);

    expect($empty['saved'])->toBeNull()
        ->and($empty['build']['route'])->toBe('builder')
        ->and($empty['build']['resume_step'])->toBe('builder')
        ->and(draftOf(['route' => 'scratch'])['build']['resume_step'])->toBe('scratch')
        ->and(draftOf(['route' => null])['build']['route'])->toBe('scratch')
        ->and($withDays['build']['resume_step'])->toBe('slots')
        ->and(array_column($empty['priority_options'], 'title'))->toBe(['Emphasize', 'Grow', 'Maintain']);
});

it('MESO-007 a mesocycle saved without dates opens in the create flow to be finished', function () {
    $undated = W::mesocycle(['start_date' => null]);
    $payload = shown(W::snapshot(mesocycles: [$undated]), $undated);

    expect($payload['saved'])->toBeNull()
        ->and($payload['build']['resume_step'])->toBe('slots');
});

it('MESO-004 the draft\'s length is TBL-020\'s for the profile, and it starts next plan week', function () {
    $intermediate = draftOf()['build'];
    $advanced = draftOf(world: ['experience' => 'advanced'])['build'];

    expect($intermediate['weeks'])->toBe(5)
        ->and(array_column($intermediate['weeks_options'], 'value'))->toBe([5, 6, 7, 8])
        ->and($intermediate['weeks_options'][0]['text'])->toBe('5 weeks')
        ->and(array_column($advanced['weeks_options'], 'value'))->toBe([4, 5, 6, 7])
        ->and($intermediate['start_date'])->toBe('2026-10-12')
        ->and($intermediate['start_text'])->toBe('12 Oct 2026')
        ->and(draftOf(['start_date' => '2026-10-21'])['build']['start_date'])->toBe('2026-10-19');
});

it('MESO-003 the draft takes the phase covering its start, its rep ranges and its priority preset', function () {
    $build = draftOf(world: cutWorld())['build'];

    expect($build['phase_title'])->toBe('Fat loss')
        ->and($build['rep_range_text'])->toBe('10–20 and 20–30 reps')
        ->and($build['phase_warning'])->toBeNull()
        ->and(array_unique(array_column($build['priorities'], 'priority')))->toBe(['emphasize'])
        ->and(draftOf()['build']['rep_range_text'])->toBe('Mixed rep ranges, with more 5–10');
});

it('MESO-003 a draft no phase covers warns and cannot be finished', function () {
    $build = draftOf(['name' => 'Next', 'days' => W::days()], ['phases' => []])['build'];

    expect($build['phase_title'])->toBeNull()
        ->and($build['phase_warning'])->toBe(['text' => 'No phase covers this date. Pick a start inside your plan.', 'tone' => 'warning'])
        ->and($build['can_finish'])->toBeFalse()
        ->and(draftOf(['name' => 'Next', 'days' => W::days()])['build']['can_finish'])->toBeTrue()
        ->and(draftOf(['days' => W::days()])['build']['can_finish'])->toBeFalse();
});

it('MESO-005 a day shows its own label, else its weekday\'s name, else "Day i", as the saved view does', function () {
    $weekdays = draftOf(['days' => W::days()])['build'];
    $mixed = draftOf(['days' => [W::days()[0], array_replace(W::days()[1], ['weekday' => null])]])['build'];
    $floating = draftOf(['days' => array_map(fn (array $day) => array_replace($day, ['weekday' => null]), W::days())])['build'];
    $blank = [array_replace(W::days()[0], ['label' => '']), array_replace(W::days()[1], ['weekday' => null, 'label' => ''])];
    $unlabelled = draftOf(['days' => $blank])['build'];

    expect($weekdays['label_style'])->toBe('weekdays')
        ->and(array_column($weekdays['days'], 'label'))->toBe(['Upper A', 'Lower A'])
        ->and($mixed['label_style'])->toBe('weekdays')
        ->and(array_column($mixed['days'], 'label'))->toBe(['Upper A', 'Lower A'])
        ->and($floating['label_style'])->toBe('floating')
        ->and(array_column($floating['days'], 'label'))->toBe(['Upper A', 'Lower A'])
        ->and(array_column($unlabelled['days'], 'label'))->toBe(['Monday', 'Day 2'])
        ->and(array_column(saved(meso: ['days' => $blank])['weeks'][0]['days'], 'label'))->toBe(['Monday', 'Day 2'])
        ->and($weekdays['weekdays_available'])->toBeTrue()
        ->and(draftOf(world: ['trainingDays' => []])['build']['weekdays_available'])->toBeFalse()
        ->and($weekdays['days_per_week'])->toBe(2)
        ->and(array_column($weekdays['days_per_week_options'], 'value'))->toBe([2, 3, 4, 5, 6]);
});

it('MESO-013 MESO-014 a draft\'s slots name their exercise, and pick for me fills the empty ones', function () {
    $days = W::days();
    $days[1]['slots'][0]['exercise_id'] = null;
    $build = draftOf(['days' => $days])['build'];

    expect($build['days'][0]['slots'][0])->toBe([
        'id' => 'ua-1', 'muscle' => 'chest', 'muscle_title' => 'Chest', 'sets' => 3, 'sets_text' => '3 sets',
        'exercise_id' => '12', 'exercise_name' => 'Bench Press',
    ])
        ->and($build['days'][1]['slots'][0]['exercise_name'])->toBeNull()
        ->and($build['can_pick_for_me'])->toBeTrue()
        ->and(draftOf(['days' => W::days()])['build']['can_pick_for_me'])->toBeFalse()
        ->and(array_column($build['sets_options'], 'value'))->toBe(range(1, 20))
        ->and($build['sets_options'][0]['text'])->toBe('1 set')
        ->and(array_column($build['exercise_options'], 'muscle'))->toHaveCount(14)
        ->and(array_column($build['exercise_options'][0]['exercises'], 'name'))->toBe(['Bench Press', 'Cable Fly']);
});

it('MESO-021 priorities past the phase\'s suggestion warn and do not block', function () {
    $build = draftOf(['muscle_priorities' => ['chest' => 'emphasize', 'back' => 'emphasize', 'quads' => 'emphasize']])['build'];

    expect($build['warnings'])->toBe([['text' => 'Emphasizing more than 2 muscles at once spreads your recovery thin', 'tone' => 'warning']]);
});

it('MESO-065 a copy whose priorities were reset to the new phase\'s preset says so', function () {
    // Meso 2 (muscle gain) is copied into a draft starting under fat loss, with the fat loss preset.
    $world = ['phases' => [
        W::phase(W::GAIN, 'muscleGain', '2026-07-27', '2026-10-12'),
        W::phase(W::CUT, 'fatLoss', '2026-10-12', null),
    ]];
    $preset = array_fill_keys(['chest', 'back', 'quads', 'hamstrings', 'glutes', 'sideDelts', 'rearDelts', 'frontDelts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'], 'emphasize');
    $copy = ['route' => 'copy_previous', 'copied_from_id' => W::MESO, 'start_date' => '2026-10-12', 'days' => W::days()];

    expect(draftOf([...$copy, 'muscle_priorities' => $preset], $world)['build']['priority_notice'])
        ->toBe(['text' => 'Priorities reset for Fat loss. Your old priorities can be kept.', 'tone' => 'info'])
        ->and(draftOf([...$copy, 'muscle_priorities' => ['chest' => 'emphasize']], $world)['build']['priority_notice'])->toBeNull()
        ->and(draftOf([...$copy, 'muscle_priorities' => $preset])['build']['priority_notice'])->toBeNull();
});

it('MESO-065 the notice follows the mesocycle the draft was copied from, and a draft with no source has none', function () {
    // Meso 1 ran under fat loss, so a copy of it into fat loss keeps its priorities: nothing was reset, though a copy
    // of Meso 2 (muscle gain, the one Copy offers) would have been.
    $phases = [
        W::phase('old-cut', 'fatLoss', '2026-06-01', '2026-07-27'),
        W::phase(W::GAIN, 'muscleGain', '2026-07-27', '2026-10-12'),
        W::phase(W::CUT, 'fatLoss', '2026-10-12', null),
    ];
    $meso1 = W::mesocycle(['id' => 'meso-1', 'name' => 'Meso 1', 'phase_id' => 'old-cut', 'start_date' => '2026-06-01']);
    $preset = array_fill_keys(['chest', 'back', 'quads', 'hamstrings', 'glutes', 'sideDelts', 'rearDelts', 'frontDelts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'], 'emphasize');
    $notice = function (?string $source) use ($phases, $meso1, $preset) {
        $draft = W::mesocycle([
            'id' => 'draft', 'name' => null, 'is_draft' => true, 'phase_id' => W::CUT, 'route' => 'copy_previous',
            'copied_from_id' => $source, 'start_date' => '2026-10-12', 'muscle_priorities' => $preset,
        ]);

        return shown(W::snapshot(mesocycles: [$meso1, W::mesocycle(), $draft], phases: $phases), $draft)['build']['priority_notice'];
    };

    expect($notice(W::MESO))->toBe(['text' => 'Priorities reset for Fat loss. Your old priorities can be kept.', 'tone' => 'info'])
        ->and($notice('meso-1'))->toBeNull()
        ->and($notice(null))->toBeNull();
});

it('MESO-020 a build shows the days per week the user picked', function () {
    expect(draftOf(['days_per_week' => 5, 'days' => W::days()])['build']['days_per_week'])->toBe(5)
        ->and(draftOf(['days_per_week' => 4])['build']['days_per_week'])->toBe(4);
});

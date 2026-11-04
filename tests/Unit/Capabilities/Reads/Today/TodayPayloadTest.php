<?php

use App\Capabilities\Reads\Today\TodayPayload;
use App\Capabilities\Reads\Today\WeekPayload;
use App\Models\MuscleFeedback;
use App\Support\Today\TodaySnapshot;
use Tests\Unit\Capabilities\Reads\Today\TodayWorld as W;

// Today (rp-clone TodayTimelineTests, TodayNextMesocycleTests, TodayWeekStripTests' ONB-031), from in-memory rows.

function todayPayload(TodaySnapshot $snapshot): array
{
    return TodayPayload::build($snapshot)->toArray();
}

function todayWeek(TodaySnapshot $snapshot): array
{
    return WeekPayload::build($snapshot)->toArray();
}

/** @return array<string, mixed> the payload's entry of `kind` with this id */
function todayItem(array $payload, string $list, string $id): array
{
    return collect($payload[$list])->firstWhere('id', $id);
}

function todayLabels(array $payload): array
{
    return array_map(fn (array $entry) => match ($entry['kind']) {
        'weigh_in' => 'Weigh-in',
        'workout' => $payload['workouts'][0]['title'],
        'meal' => todayItem($payload, 'meals', $entry['id'])['name'],
        'busy' => todayItem($payload, 'busy_periods', $entry['id'])['label'],
        'other_activity' => todayItem($payload, 'other_activities', $entry['id'])['name'],
    }, $payload['timeline']);
}

it('SCH-001 lists the day\'s items in time order', function () {
    $payload = todayPayload(W::snapshot());

    expect(todayLabels($payload))->toBe(['Weigh-in', 'Breakfast', 'Team meeting', 'Lunch', 'Week 2 · Day 2 · Lower A', 'Dinner'])
        ->and(array_column($payload['timeline'], 'kind'))->toBe(['weigh_in', 'meal', 'busy', 'meal', 'workout', 'meal'])
        ->and($payload['title'])->toBe('Wednesday, Sep 23')
        ->and($payload['date'])->toBe('2026-09-23');
});

it('SCH-001 orders items at the same minute weigh-in, meal, workout, other activity, busy period', function () {
    $payload = todayPayload(W::snapshot(
        profile: [
            'training_days' => [
                ['weekday' => 1, 'start' => '08:00', 'minutes' => 75],
                ['weekday' => 3, 'start' => '08:00', 'minutes' => 75],
                ['weekday' => 5, 'start' => '08:00', 'minutes' => 75],
            ],
            'busy_periods' => [['label' => 'Team meeting', 'start' => '08:00', 'end' => '09:00', 'weekdays' => [3]]],
        ],
        activities: [W::activity('act', start: '08:00')],
    ));

    expect(array_slice(array_column($payload['timeline'], 'kind'), 1, 4))->toBe(['meal', 'workout', 'other_activity', 'busy'])
        ->and($payload['timeline'][1]['id'])->toBe(W::mealId('2026-09-23', 1));
});

it('SCH-001 ONB-031 plans nothing before the plan starts, and upcoming days can still be browsed', function () {
    $before = todayPayload(W::snapshot(today: '2026-09-10'));
    $upcoming = todayPayload(W::snapshot(today: '2026-09-10', date: '2026-09-14'));

    expect($before['timeline'])->toBe([])
        ->and($before['totals'])->toBeNull()
        ->and($before['banners'])->toBe([['text' => 'Your plan starts Sat, Sep 12', 'tone' => 'info']])
        ->and(count($upcoming['meals']))->toBe(3)
        ->and($upcoming['workouts'][0]['title'])->toBe('Week 1 · Day 1 · Upper A');
});

it('ONB-002 setup is needed until the user has a plan, so Today sends them back to it', function () {
    expect(todayPayload(W::snapshot(withPlan: false))['setup_needed'])->toBeTrue()
        ->and(todayPayload(W::snapshot())['setup_needed'])->toBeFalse()
        ->and(todayPayload(W::snapshot(today: '2026-09-10'))['setup_needed'])->toBeFalse();
});

it('ONB-031 shows no banner once the plan has started', function () {
    expect(todayPayload(W::snapshot())['banners'])->toBe([])
        ->and(todayPayload(W::snapshot(today: '2026-09-12'))['banners'])->toBe([]);
});

it('SCH-002 a meal card shows its name, time, targets and planned foods', function () {
    $payload = todayPayload(W::snapshot());
    $breakfast = $payload['meals'][0];

    expect($breakfast['name'])->toBe('Breakfast')
        ->and($breakfast['time'])->toBe('08:00')
        ->and($breakfast['time_text'])->toBe('8:00 AM')
        ->and($breakfast['condensed_time_text'])->toBe('8:00 AM · 780 kcal')
        ->and($breakfast['macros'])->toBe([
            'kcal' => '780 kcal', 'protein' => 'P 51', 'fat' => 'F 21', 'carbs' => 'C 96',
            'label' => '780 kcal, 51 grams protein, 21 grams fat, 96 grams carbs',
        ])
        ->and($breakfast['planned_text'])->toBe('Oats 80 g · Whey 30 g')
        ->and($payload['meals'][2]['planned_text'])->toBeNull()
        ->and($payload['meals'][1]['eaten_text'])->toBe('Eaten: 790 kcal, 55 g protein')
        ->and($breakfast['eaten_text'])->toBeNull();
});

it('SCH-002 a meal without a name or time is "Meal n" and goes last', function () {
    $snapshot = W::snapshot();
    $target = $snapshot->dayTarget($snapshot->date);
    $meals = $target->meals;
    $meals[0]['name'] = null;
    $meals[0]['time'] = null;
    $target->forceFill(['meals' => $meals]);
    $payload = todayPayload($snapshot);

    expect($payload['meals'][0]['name'])->toBe('Meal 1')
        ->and($payload['meals'][0]['time'])->toBeNull()
        ->and($payload['meals'][0]['condensed_time_text'])->toBe('780 kcal')
        ->and(end($payload['timeline']))->toBe(['kind' => 'meal', 'id' => W::mealId('2026-09-23', 1)]);
});

it('SCH-003 a workout card shows its name, time, length, exercises and sets', function () {
    $workout = todayPayload(W::snapshot())['workouts'][0];

    expect($workout['title'])->toBe('Week 2 · Day 2 · Lower A')
        ->and($workout['name'])->toBe('Lower A')
        ->and($workout['time'])->toBe('17:30')
        ->and($workout['time_text'])->toBe('5:30 PM')
        ->and($workout['condensed_time_text'])->toBe('5:30 PM · 30 min')
        ->and($workout['summary_text'])->toBe('30 min · 2 exercises · 7 sets')
        ->and($workout['exercises'])->toBe([
            ['name' => 'Back Squat', 'sets_text' => '4 sets'],
            ['name' => 'Exercise not chosen yet', 'sets_text' => '3 sets'],
        ])
        ->and($workout['state'])->toBe('planned')
        ->and($workout['status_text'])->toBeNull()
        ->and($workout['completed'])->toBeNull()
        ->and([$workout['mesocycle_id'], $workout['week'], $workout['day']])->toBe([W::MESO, 2, 2]);
});

it('SCH-003 a finished workout shows Done and the sets completed', function () {
    $workout = todayPayload(W::snapshot(date: '2026-09-21'))['workouts'][0];

    expect($workout['title'])->toBe('Week 2 · Day 1 · Upper A')
        ->and($workout['state'])->toBe('done')
        ->and($workout['status_text'])->toBe('Done')
        ->and($workout['completed'])->toBe(['text' => '8 of 10 sets completed', 'tone' => 'good'])
        ->and($workout['menu'])->toBe([])
        ->and($workout['move_days'])->toBe([]);
});

it('SCH-003 SCH-046 three past sessions of the day replace the estimate, and a deload halves the sets', function () {
    $workout = todayPayload(W::snapshot(today: '2026-10-05'))['workouts'][0];

    expect($workout['title'])->toBe('Week 4 · Day 1 · Upper A')
        // The mean of 40, 50 and 45 minutes, not the 10 sets' estimate.
        ->and($workout['summary_text'])->toBe('45 min · 3 exercises · 4 sets');
});

it('SCH-003 a day without a session has no workout card', function () {
    expect(todayPayload(W::snapshot(date: '2026-09-22'))['workouts'])->toBe([]);
});

it('SCH-003 SCH-053 a skipped session shows Skipped and cannot be moved', function () {
    $sessions = [...W::sessions(), W::session('w2d3', 2, 3, skipped: true)];
    $workout = todayPayload(W::snapshot(date: '2026-09-25', sessions: $sessions))['workouts'][0];

    expect($workout['state'])->toBe('skipped')
        ->and($workout['status_text'])->toBe('Skipped')
        ->and($workout['menu'])->toBe([]);
});

it('SCH-003 a started session is started', function () {
    $sessions = [...array_slice(W::sessions(), 0, 3), W::session('w2d2', 2, 2, '2026-09-23 17:35:00')];

    expect(todayPayload(W::snapshot(sessions: $sessions))['workouts'][0]['state'])->toBe('started');
});

it('SCH-038 SCH-051 a planned workout moves only to another day of its plan week from today on, never onto a session', function () {
    $workout = todayPayload(W::snapshot())['workouts'][0];

    expect(array_column($workout['menu'], 'action'))->toBe(['change_time', 'move_day'])
        ->and($workout['move_days'])->toBe([
            ['date' => '2026-09-24', 'label' => 'Thu, Sep 24', 'enabled' => true, 'note' => null],
            ['date' => '2026-09-25', 'label' => 'Fri, Sep 25', 'enabled' => false, 'note' => 'This day already has a workout. Pick a different day.'],
        ]);
});

it('SCH-004 the weigh-in row shows the scheduled time and the logged weight', function () {
    $payload = todayPayload(W::snapshot());

    expect($payload['weigh_ins'])->toBe([['id' => 'weigh-in', 'time_text' => '7:00 AM', 'weight_text' => '82.4 kg']]);
});

it('SCH-004 SET-001 the weight shows in the user\'s unit', function () {
    expect(todayPayload(W::snapshot(profile: ['weight_unit' => 'lb']))['weigh_ins'][0]['weight_text'])->toBe('181.7 lb');
});

it('SCH-004 a weigh-in day with no weigh-in offers to add one; other days have no row', function () {
    expect(todayPayload(W::snapshot(date: '2026-09-19'))['weigh_ins'])->toBe([['id' => 'weigh-in', 'time_text' => '7:30 AM', 'weight_text' => null]])
        ->and(todayPayload(W::snapshot(date: '2026-09-22'))['weigh_ins'])->toBe([]);
});

it('SCH-005 a busy period shows its label and range on its weekdays only', function () {
    expect(todayPayload(W::snapshot())['busy_periods'])->toBe([
        ['id' => 'busy-team-meeting-0900', 'label' => 'Team meeting', 'time_text' => '9:00 AM – 10:30 AM'],
    ])
        ->and(todayPayload(W::snapshot(date: '2026-09-19'))['busy_periods'])->toBe([]);
});

it('SCH-006 DAY-011 the totals show consumed against target and the day type', function () {
    $totals = todayPayload(W::snapshot())['totals'];

    // Lunch is checked in; breakfast counts at its planned foods and dinner at its targets.
    expect($totals['day_type'])->toBe(['text' => 'Hard', 'tone' => 'neutral'])
        ->and($totals['calories'])->toBe(['label' => 'Cal', 'value_text' => '2254/2600', 'fraction' => 0.867, 'tone' => 'neutral'])
        ->and($totals['protein']['value_text'])->toBe('157/170')
        ->and($totals['status'])->toBe(['text' => '346 under', 'tone' => 'warning']);
});

it('SCH-006 SET-003 counting only what was eaten leaves meals not checked in out', function () {
    $totals = todayPayload(W::snapshot(profile: ['consumed_totals' => 'checked_in']))['totals'];

    expect($totals['calories']['value_text'])->toBe('790/2600')
        ->and($totals['calories']['fraction'])->toBe(0.304);
});

it('SCH-006 DAY-001 off-plan food counts as eaten', function () {
    $payload = todayPayload(W::snapshot(offPlan: [W::offPlan('op1', name: 'Office cake', time: '15:00', kcal: 320, protein: 4)]));

    expect($payload['totals']['calories']['value_text'])->toBe('2574/2600')
        ->and($payload['off_plan'])->toBe([[
            'id' => 'op1', 'name' => 'Office cake', 'time_text' => '3:00 PM',
            'macros' => ['kcal' => '320 kcal', 'protein' => 'P 4', 'fat' => 'F 10', 'carbs' => 'C 30', 'label' => '320 kcal, 4 grams protein, 10 grams fat, 30 grams carbs'],
        ]]);
});

it('SCH-006 a day eaten as planned is on track', function () {
    $totals = todayPayload(W::snapshot(date: '2026-09-22'))['totals'];

    expect($totals['day_type']['text'])->toBe('Rest')
        ->and($totals['status'])->toBe(['text' => 'on track', 'tone' => 'good']);
});

it('SCH-006 PLAN-022 a deload week is labelled Deload', function () {
    expect(todayPayload(W::snapshot(date: '2026-10-06'))['totals']['day_type']['text'])->toBe('Deload');
});

it('SCH-006 a day with no target has no totals bar', function () {
    expect(todayPayload(W::snapshot(date: '2026-10-12'))['totals'])->toBeNull();
});

it('SCH-007 every meal and workout card carries its condensed line', function () {
    $payload = todayPayload(W::snapshot());

    expect(array_column($payload['meals'], 'condensed_time_text'))->toBe(['8:00 AM · 780 kcal', '12:30 PM · 780 kcal', '7:30 PM · 1040 kcal'])
        ->and($payload['workouts'][0]['condensed_time_text'])->toBe('5:30 PM · 30 min');
});

it('SCH-008 an empty meal not eaten yet prompts to plan it', function () {
    $meals = todayPayload(W::snapshot())['meals'];

    expect(array_column($meals, 'plan_banner'))->toBe([null, null, 'Plan this meal before you eat it']);
});

it('SCH-009 MEAL-043 the check-in box says how the meal was eaten', function () {
    $meals = todayPayload(W::snapshot())['meals'];
    $monday = todayPayload(W::snapshot(date: '2026-09-21'))['meals'];

    expect(array_column(array_column($meals, 'check_in'), 'state'))->toBe(['not_checked_in', 'on_track', 'not_checked_in'])
        ->and($meals[1]['check_in']['label'])->toBe('Checked in, on track')
        ->and(array_column(array_column($monday, 'check_in'), 'state'))->toBe(['under', 'over', 'not_checked_in']);
});

it('SCH-009 MEAL-041 the manual check-in opens at the eaten amounts, else the one-tap ones', function () {
    $meals = todayPayload(W::snapshot())['meals'];

    expect($meals[0]['check_in']['manual'])->toBe(['time' => '08:00', 'kcal' => 424.0, 'protein_g' => 34.4, 'fat_g' => 7.4, 'carbs_g' => 55.2])
        ->and($meals[1]['check_in']['manual'])->toBe(['time' => '12:40', 'kcal' => 790.0, 'protein_g' => 55.0, 'fat_g' => 20.0, 'carbs_g' => 100.0])
        ->and($meals[2]['check_in']['manual']['kcal'])->toBe(1040.0);
});

it('SCH-010 MEAL-009 the meal menu offers what fits the meal', function () {
    $meals = todayPayload(W::snapshot())['meals'];
    $enabled = fn (array $meal) => array_column(array_filter($meal['menu'], fn ($item) => $item['enabled']), 'action');

    expect(array_column($meals[0]['menu'], 'label'))->toBe([
        'Edit macros/time', 'Load saved meal', 'Save as saved meal', 'Copy foods', 'Remove foods', 'Check in meal',
        'Check in at manual macros', 'Remove check-in', 'Still planning foods', 'Delete meal',
    ])
        ->and(array_values($enabled($meals[0])))->not->toContain('remove_check_in')
        ->and(array_values($enabled($meals[1])))->toContain('remove_check_in')->not->toContain('check_in_meal')
        ->and(array_values($enabled($meals[2])))->not->toContain('copy_foods')->not->toContain('remove_foods');
});

it('SCH-010 DAY-003 MEAL-045 a meal still being planned that misses its targets is badged and cannot be ticked', function () {
    $breakfast = W::mealId('2026-09-23', 1);
    $meals = todayPayload(W::snapshot(states: [$breakfast => 'still_planning']))['meals'];
    $fitted = todayPayload(W::snapshot(foods: [$breakfast => [['fit', 100]]], states: [$breakfast => 'still_planning']))['meals'];

    expect($meals[0]['badges'])->toBe([['text' => 'Adjust foods', 'tone' => 'warning']])
        ->and($meals[0]['check_in']['blocked_message'])->not->toBeNull()
        ->and(collect($meals[0]['menu'])->firstWhere('action', 'check_in_meal')['enabled'])->toBeFalse()
        ->and(collect($meals[0]['menu'])->firstWhere('action', 'done_planning')['label'])->toBe('Done planning foods')
        ->and($fitted[0]['badges'])->toBe([])
        ->and($fitted[0]['check_in']['blocked_message'])->toBeNull();
});

it('SCH-011 the step row shows the day\'s target from the step plan', function () {
    expect(todayPayload(W::snapshot())['steps'])->toBe(['target_text' => 'Step count target 6-8k', 'actual_text' => null])
        ->and(todayPayload(W::snapshot(date: '2026-09-19'))['steps']['target_text'])->toBe('Step count target 0-8k')
        ->and(todayPayload(W::snapshot(profile: ['step_plan' => [['weekday' => 3, 'min' => 6500, 'max' => 7500]]]))['steps']['target_text'])
        ->toBe('Step count target 6.5-7.5k');
});

it('SCH-011 no step plan for the day hides the row', function () {
    expect(todayPayload(W::snapshot(date: '2026-09-24'))['steps'])->toBeNull();
});

it('SCH-011 the step row shows the actual steps when there are any', function () {
    expect(todayPayload(W::snapshot(steps: 5432))['steps']['actual_text'])->toBe('5,432 steps')
        ->and(todayPayload(W::snapshot(steps: 1))['steps']['actual_text'])->toBe('1 step');
});

it('SCH-012 on today the Now line sits before the first item still to come and names it', function () {
    $payload = todayPayload(W::snapshot(nowTime: '07:30'));

    expect($payload['now'])->toBe(['before' => $payload['timeline'][1], 'label' => 'Now', 'next_text' => 'Next: Breakfast at 8:00 AM'])
        ->and(todayLabels($payload)[1])->toBe('Breakfast');
});

it('SCH-012 the Now line passes over what is already done: a logged weigh-in, a checked-in meal, a finished workout', function () {
    $early = todayPayload(W::snapshot(nowTime: '06:00'));
    $lunch = todayPayload(W::snapshot(nowTime: '10:00'));
    $monday = todayPayload(W::snapshot(today: '2026-09-21', nowTime: '17:00'));

    expect($early['now'])->toBe(['before' => $early['timeline'][0], 'label' => 'Now', 'next_text' => 'Next: Breakfast at 8:00 AM'])
        ->and($lunch['now'])->toBe(['before' => $lunch['timeline'][3], 'label' => 'Now', 'next_text' => 'Next: Lower A at 5:30 PM'])
        ->and($monday['now']['before'])->toBe(['kind' => 'workout', 'id' => 'workout'])
        ->and($monday['workouts'][0]['state'])->toBe('done')
        ->and($monday['now']['next_text'])->toBe('Next: Dinner at 7:30 PM');
});

it('SCH-012 an item due this minute is still to come, and after the last item the line names nothing', function () {
    $due = todayPayload(W::snapshot(nowTime: '12:30'));
    $late = todayPayload(W::snapshot(nowTime: '23:00'));

    expect($due['now']['before'])->toBe($due['timeline'][3])
        ->and(todayLabels($due)[3])->toBe('Lunch')
        ->and($late['now'])->toBe(['before' => null, 'label' => 'Now', 'next_text' => null]);
});

it('SCH-012 other days, and a today with nothing planned, have no Now line', function () {
    expect(todayPayload(W::snapshot(date: '2026-09-24'))['now'])->toBeNull()
        ->and(todayPayload(W::snapshot(date: '2026-09-22'))['now'])->toBeNull()
        ->and(todayPayload(W::snapshot(today: '2026-09-10'))['now'])->toBeNull();
});

it('SCH-030 NUT-024 a past day offers neither Add meal nor Optimize', function () {
    $future = todayPayload(W::snapshot());
    $past = todayPayload(W::snapshot(date: '2026-09-21'));

    expect(array_column($future['schedule_menu'], 'action'))->toBe([
        'add_meal', 'add_workout', 'add_busy_period', 'copy_day', 'copy_week', 'optimize_schedule', 'edit_schedule',
    ])
        ->and(array_column($past['schedule_menu'], 'action'))->toBe(['add_workout', 'add_busy_period', 'copy_day', 'copy_week', 'edit_schedule'])
        ->and($future['add_meal_time'])->toBe('14:30')
        ->and($past['add_meal_time'])->toBeNull()
        ->and($past['add_workout'])->toBeNull();
});

it('SCH-030 on a day still to come, Add meal and Add workout show only when their sheets have what they open with', function () {
    $before = todayPayload(W::snapshot(today: '2026-09-10'));
    $noTarget = todayPayload(W::snapshot(today: '2026-10-12'));

    expect($before['add_meal_time'])->toBeNull()
        ->and($before['add_workout'])->toBeNull()
        ->and(array_column($before['schedule_menu'], 'action'))->toBe(['add_busy_period', 'copy_day', 'copy_week', 'optimize_schedule', 'edit_schedule'])
        ->and($noTarget['add_meal_time'])->toBeNull()
        ->and($noTarget['add_workout'])->not->toBeNull()
        ->and(array_column($noTarget['schedule_menu'], 'action'))->not->toContain('add_meal')->toContain('add_workout');
});

it('SCH-032 Add workout offers the next session of the week to move here, or says why not', function () {
    $rest = todayPayload(W::snapshot(date: '2026-09-24'))['add_workout'];
    $training = todayPayload(W::snapshot(activities: [W::activity('act')]))['add_workout'];

    expect($rest['session'])->toBe(['mesocycle_id' => W::MESO, 'week' => 2, 'day' => 3, 'title' => 'Week 2 · Day 3 · Upper B', 'time_text' => '5:30 PM'])
        ->and($rest['session_message'])->toBeNull()
        ->and($training['session'])->toBeNull()
        ->and($training['session_message'])->toBe('This day already has a workout. Pick a different day.')
        ->and($training['activity'])->toBe(['id' => 'act', 'name' => 'Tennis', 'start' => '18:45', 'minutes' => 45, 'intensity' => 'moderate'])
        ->and(todayPayload(W::snapshot(date: '2026-09-24', sessions: [...W::sessions(), W::session('w2d3', 2, 3, skipped: true)]))['add_workout']['session_message'])
        ->toBe('No session left to move this week');
});

it('SCH-032 an Other activity shows on the timeline at its start', function () {
    $payload = todayPayload(W::snapshot(activities: [W::activity('act')]));

    expect($payload['other_activities'])->toBe([[
        'id' => 'act', 'name' => 'Tennis', 'time_text' => '6:45 PM · 45 min', 'intensity_text' => 'Moderate',
        'menu' => [['action' => 'edit', 'label' => 'Edit'], ['action' => 'delete', 'label' => 'Delete']],
    ]])
        ->and(array_column($payload['timeline'], 'kind'))->toBe(['weigh_in', 'meal', 'busy', 'meal', 'workout', 'other_activity', 'meal']);
});

it('SCH-037 SCH-039 Edit schedule opens with the profile\'s schedule and training weekdays', function () {
    $schedule = todayPayload(W::snapshot())['schedule'];

    expect($schedule['this_day'])->toBe([
        'window_start' => '07:00', 'window_end' => '22:00', 'meal_count' => 3,
        'busy_periods' => [['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30']], 'training_start' => '17:30',
    ])
        ->and(array_column(array_filter($schedule['training_weekdays'], fn ($chip) => $chip['selected']), 'label'))->toBe(['Mon', 'Wed', 'Fri'])
        ->and(todayPayload(W::snapshot(date: '2026-09-22'))['schedule']['this_day']['training_start'])->toBeNull();
});

it('MESO-060 PLAN-027 from the deload week, Today prompts to build the next mesocycle', function () {
    $before = todayPayload(W::snapshot(today: '2026-10-02'));
    $deload = todayPayload(W::snapshot(today: '2026-10-05'));

    expect($before['next_mesocycle'])->toBeNull()
        ->and($deload['next_mesocycle'])->toBe([
            'mesocycle_id' => W::MESO,
            'title' => 'Build your next mesocycle',
            'start_text' => 'Starts Saturday, Oct 10',
            'options' => [
                ['route' => 'copy_previous', 'label' => 'Copy this mesocycle'],
                ['route' => 'template', 'label' => 'Start from a template'],
                ['route' => 'builder', 'label' => 'Build from scratch'],
            ],
            'active_rest' => null,
        ]);
});

it('MESO-060 the prompt goes once a mesocycle starting after it is saved; a draft counts for nothing', function () {
    $later = W::mesocycle(['id' => 'meso-b', 'name' => 'Meso B', 'start_date' => '2026-10-10', 'weeks' => 5]);
    $draft = W::mesocycle(['id' => 'draft', 'is_draft' => true, 'start_date' => null, 'weeks' => null]);

    expect(todayPayload(W::snapshot(today: '2026-10-05', mesocycles: [W::mesocycle(), $later]))['next_mesocycle'])->toBeNull()
        ->and(todayPayload(W::snapshot(today: '2026-10-05', mesocycles: [W::mesocycle(), $draft]))['next_mesocycle'])->not->toBeNull();
});

it('MESO-060 MESO-043 a mesocycle ended early prompts from the day it ended', function () {
    $ended = W::mesocycle(['ended_on' => '2026-09-22']);

    expect(todayPayload(W::snapshot(mesocycles: [$ended]))['next_mesocycle']['start_text'])->toBe('Starts Saturday, Sep 26');
});

it('MESO-060 MESO-064 flat training offers an active rest a plan week later, until it is taken', function () {
    // Two flat weeks running (PROG-054).
    $sessions = array_slice(W::sessions(), 0, 3);
    $flat = fn (string $session) => (new MuscleFeedback)->forceFill([
        'id' => "mf-{$session}", 'user_id' => 1, 'workout_session_id' => $session, 'muscle_group' => 'chest', 'pump' => 'low', 'soreness' => 'neverGotSore',
    ]);
    $feedback = array_map($flat, ['w2d1', 'w3d1']);
    $offered = todayPayload(W::snapshot(today: '2026-10-05', sessions: $sessions, feedback: $feedback))['next_mesocycle'];
    $taken = todayPayload(W::snapshot(today: '2026-10-05', mesocycles: [W::mesocycle(['active_rest' => true])], sessions: $sessions, feedback: $feedback))['next_mesocycle'];

    expect($offered['active_rest'])->toBe([
        'text' => 'A week of active rest is recommended before your next mesocycle.',
        'start_text' => 'Starts Saturday, Oct 17',
        'start_date' => '2026-10-17',
    ])
        ->and($taken['start_text'])->toBe('Starts Saturday, Oct 17')
        ->and($taken['active_rest'])->toBeNull();
});

it('SCH-002 RCP-002 FOOD-006 planned recipe servings and cooked foods count at their macros, in their planned order', function () {
    $dinner = W::mealId('2026-09-23', 3);
    $lunch = W::mealId('2026-09-23', 2);
    $payload = todayPayload(W::snapshot(
        foods: [W::mealId('2026-09-23', 1) => [['oats', 80], ['whey', 30]], $dinner => [['rice', 250, true, 1]]],
        recipes: [$dinner => [[2, 0]], $lunch => [[1, 0]]],
    ));
    $meal = $payload['meals'][2];

    // 2 servings of chili are 700 kcal and 66 g protein; 250 g of cooked rice is 100 g raw, 360 kcal.
    expect($meal['planned_text'])->toBe('Chili 2 servings · Rice 250 g')
        ->and($payload['meals'][1]['planned_text'])->toBe('Chili 1 serving')
        ->and($meal['check_in']['manual'])->toBe(['time' => '19:30', 'kcal' => 1060.0, 'protein_g' => 73.0, 'fat_g' => 32.0, 'carbs_g' => 114.0])
        ->and($meal['plan_banner'])->toBeNull()
        ->and($payload['totals']['calories']['value_text'])->toBe('2274/2600');
});

it('SCH-040 SCH-060 an away week that pauses training plans no sessions, and the mesocycle resumes after it', function () {
    $planWeeks = [W::planWeek('2026-09-26', away: true, awayTraining: 'pause')];
    $sessions = array_slice(W::sessions(), 0, 2);
    $paused = todayWeek(W::snapshot(date: '2026-09-28', planWeeks: $planWeeks, sessions: $sessions));
    $after = todayPayload(W::snapshot(today: '2026-10-05', planWeeks: $planWeeks, sessions: $sessions));

    expect(array_filter(array_column($paused['days'], 'has_training')))->toBe([])
        ->and($paused['pills'])->toBe([['text' => 'Away', 'tone' => 'info']])
        ->and($after['workouts'][0]['title'])->toBe('Week 3 · Day 1 · Upper A');
});

it('SCH-060 PLAN-022 an away week taken as the deload halves the sets and shows Deload', function () {
    $planWeeks = [W::planWeek('2026-09-26', away: true, awayTraining: 'deload')];
    $sessions = array_slice(W::sessions(), 0, 2);

    expect(todayWeek(W::snapshot(date: '2026-09-28', planWeeks: $planWeeks, sessions: $sessions))['pills'])
        ->toBe([['text' => 'Deload', 'tone' => 'neutral'], ['text' => 'Away', 'tone' => 'info']])
        ->and(todayPayload(W::snapshot(today: '2026-09-28', planWeeks: $planWeeks, sessions: $sessions))['workouts'][0]['summary_text'])
        ->toBe('20 min · 3 exercises · 4 sets');
});

it('SCH-022 MESO-043 a mesocycle ended early plans no sessions after its last day; logged ones stay', function () {
    $ended = [W::mesocycle(['ended_on' => '2026-09-22'])];
    $training = array_column(array_filter(todayWeek(W::snapshot(mesocycles: $ended))['days'], fn ($day) => $day['has_training']), 'number');

    expect(array_values($training))->toBe(['21'])
        ->and(todayPayload(W::snapshot(mesocycles: $ended))['workouts'])->toBe([]);
});

it('SCH-040 a mesocycle that starts mid-week plans nothing before its first day', function () {
    $later = [W::mesocycle(['start_date' => '2026-09-16'])];

    expect(todayPayload(W::snapshot(date: '2026-09-14', mesocycles: $later, sessions: []))['workouts'])->toBe([])
        ->and(todayPayload(W::snapshot(date: '2026-09-16', mesocycles: $later, sessions: []))['workouts'])->not->toBe([]);
});

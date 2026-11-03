<?php

use App\Models\OffPlanEntry;
use App\Models\OtherActivity;
use App\Models\PlanWeek;
use App\Models\Step;
use App\Models\WeighIn;
use Illuminate\Support\Carbon;
use Tests\Agent\Any;
use Tests\Agent\Coach;
use Tests\Support\SheetSeed;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// Eval requests that record something about the day or the week (AGT-050). Today is Wednesday 23 September 2026, 10 AM.
uses()->group('warp-isolated');

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('AGT-050 "I weighed 81.2 kg this morning"', function () {
    $user = TrainSeed::user();

    Coach::says($user, 'I weighed 81.2 kg this morning', calls: [
        ['weigh_in_log', ['id' => Any::id(), 'weighed_at' => new Any('2026-09-23T07:30:00+10:00'), 'weight' => ['value' => 81.2, 'unit' => 'kg']]],
    ], reply: 'Logged 81.2 kg for this morning.');

    expect((float) WeighIn::query()->where('user_id', $user->id)->sole()->weight_kg)->toBe(81.2);
});

it('AGT-050 "I walked 9,000 steps today"', function () {
    $user = TrainSeed::user();

    Coach::says($user, 'I walked 9,000 steps today', calls: [
        ['steps_log', ['date' => '2026-09-23', 'steps' => 9000]],
    ], reply: 'Logged 9,000 steps for today.');

    expect(Step::query()->where('user_id', $user->id)->whereDate('date', '2026-09-23')->sole()->count)->toBe(9000);
});

it('AGT-050 "I had a slice of birthday cake, about 350 kcal"', function () {
    $user = TrainSeed::user();
    SheetSeed::day($user);

    Coach::says($user, 'I had a slice of birthday cake, about 350 kcal', calls: [
        ['off_plan_log', ['id' => Any::id(), 'date' => '2026-09-23', 'time' => new Any('10:00'), 'kcal' => 350, 'protein_g' => new Any(4), 'fat_g' => new Any(18), 'carbs_g' => new Any(45), 'name' => new Any('Birthday cake')]],
    ], reply: 'Logged a slice of birthday cake, about 350 kcal.');

    expect((float) OffPlanEntry::query()->where('user_id', $user->id)->sole()->kcal)->toBe(350.0);
});

it('AGT-050 "log a 45 minute run tomorrow at 7am"', function () {
    $user = TrainSeed::user();
    SheetSeed::day($user, '2026-09-24');

    Coach::says($user, 'Log a 45 minute run tomorrow at 7am', calls: [
        ['other_activity_save', ['id' => Any::id(), 'date' => '2026-09-24', 'name' => new Any('Run'), 'start' => '07:00', 'minutes' => 45, 'intensity' => new Any('moderate')]],
    ], reply: 'Added a 45 minute run tomorrow at 7:00 AM.');

    $run = OtherActivity::query()->where('user_id', $user->id)->sole();
    expect($run->minutes)->toBe(45)
        ->and($run->date->format('Y-m-d'))->toBe('2026-09-24');
});

it('AGT-050 "add a snack at 3pm"', function () {
    $user = TrainSeed::user();
    $day = SheetSeed::day($user);

    Coach::says($user, 'Add a snack at 3pm', calls: [
        ['meal_add', ['id' => Any::id(), 'date' => '2026-09-23', 'time' => '15:00']],
    ], reply: 'Added a meal at 3:00 PM.');

    expect($day->fresh()->meals)->toHaveCount(4);
});

it('AGT-050 "I\'m having a rough week"', function () {
    $user = TodaySeed::user();

    Coach::says($user, 'I\'m having a rough week', calls: [
        ['rough_week_set', ['week_start' => '2026-09-19', 'rough' => true]],
    ], reply: 'Marked this week as rough. I\'ve lightened what is left of it.');

    expect(PlanWeek::query()->where('user_id', $user->id)->where('is_rough', true)->count())->toBe(1);
});

it('AGT-050 "I\'m away next week"', function () {
    $user = TodaySeed::user();
    TrainSeed::phase($user, 'fatLoss', '2026-09-12', '2026-12-05');

    Coach::says($user, 'I\'m away next week', calls: [
        ['away_week_set', ['week_starts' => ['2026-09-26'], 'away' => true, 'training' => new Any('pause'), 'calories' => new Any('maintenance')]],
    ], reply: 'Marked the week from 26 September as away.');

    expect(PlanWeek::query()->where('user_id', $user->id)->where('is_away', true)->count())->toBe(1);
});

it('AGT-050 "switch me to pounds"', function () {
    $user = TodaySeed::user();

    Coach::says($user, 'Switch me to pounds', calls: [
        ['settings_update', ['weight_unit' => 'lb', 'height_unit' => new Any('cm'), 'appearance' => new Any('system'), 'consumed_totals' => new Any('planned'), 'micro_plates' => new Any(false)]],
    ], reply: 'Your weights show in pounds now.');

    expect($user->profile->fresh()->weight_unit)->toBe('lb');
});

it('AGT-050 AGT-015 a question about the method: "what does RIR mean?"', function () {
    $user = TrainSeed::user();

    $said = Coach::says($user, 'What does RIR mean?', calls: [
        ['article_show', ['slug' => 'rir']],
    ], reply: 'RIR is reps in reserve: how many more reps you could have done. The Learn article on it has the detail.');

    expect($said->result('article_show')['data'])->not->toBeEmpty();
});

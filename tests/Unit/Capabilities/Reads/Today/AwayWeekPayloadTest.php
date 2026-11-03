<?php

use App\Capabilities\Reads\Today\AwayWeekPayload;
use App\Support\Today\AwayOffer;
use Tests\Support\ScreenFixture;
use Tests\Unit\Capabilities\Reads\Today\TodayWorld as W;

// The Away sheet (SCH-060, PLAN-025; rp-clone AwayWeekModel), from in-memory rows. The Fat loss phase runs from the
// plan start to 2026-12-05, so the weeks from 2026-09-26 to 2026-11-28 are offered; Meso A fills 2026-09-12 to 2026-10-09.

/** @param  array<string, mixed>  $world  W::snapshot() arguments */
function awaySheet(string $week, array $world = []): array
{
    $away = AwayOffer::fromTrain(W::snapshot(...array_replace(['phases' => [W::phase()], 'planWeeks' => []], $world))->train);

    return AwayWeekPayload::build($away, $week)->toArray();
}

it('SCH-060 lists the weeks offered with their number and date, the pressed one selected', function () {
    $sheet = awaySheet('2026-10-03');

    expect($sheet['is_editing'])->toBeFalse()
        ->and(array_column($sheet['weeks'], 'week_start'))->toBe(['2026-09-26', '2026-10-03', '2026-10-10', '2026-10-17', '2026-10-24', '2026-10-31', '2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'])
        ->and($sheet['weeks'][0]['label'])->toBe('Week 3 · Sep 26')
        ->and($sheet['weeks'][9]['label'])->toBe('Week 12 · Nov 28')
        ->and(array_column(array_filter($sheet['weeks'], fn ($week) => $week['selected']), 'week_start'))->toBe(['2026-10-03'])
        ->and(array_filter(array_column($sheet['weeks'], 'is_away')))->toBe([]);
});

it('SCH-060 the sheet has the shape of the screen fixture', function () {
    $sheet = awaySheet('2026-09-26');

    assertMatchesScreenFixture('away_week_show', $sheet);
    expect($sheet['training_options'][0])->toMatchArray(['value' => 'pause', 'enabled' => true])
        ->and($sheet['calorie_options'])->toBe(ScreenFixture::load('away_week_show')['calorie_options'])
        ->and($sheet['training_options'][0]['label'])->toBe('Pause training');
});

it('SCH-060 an away week opens its sheet in the editing state, standing alone', function () {
    $sheet = awaySheet('2026-10-03', ['planWeeks' => [W::planWeek('2026-10-03', away: true, awayTraining: 'deload', awayCalories: 'rest_days', keepPhaseEnd: true)]]);

    expect(ScreenFixture::differences(ScreenFixture::load('away_week_show.editing'), $sheet))->toBe([])
        ->and($sheet['is_editing'])->toBeTrue()
        ->and(array_column($sheet['weeks'], 'week_start'))->toBe(['2026-10-03'])
        ->and($sheet['weeks'][0])->toMatchArray(['is_away' => true, 'selected' => true])
        ->and([$sheet['training'], $sheet['calories']])->toBe(['deload', 'rest'])
        ->and($sheet['keep_phase_end'])->toBeTrue();
});

it('SCH-060 training opens on pause, and calories on maintenance in Fat loss and rest days otherwise', function () {
    $fatLoss = awaySheet('2026-09-26');
    $gain = awaySheet('2026-09-26', ['phases' => [W::phase('muscleGain')]]);

    expect([$fatLoss['training'], $fatLoss['calories']])->toBe(['pause', 'maintenance'])
        ->and([$gain['training'], $gain['calories']])->toBe(['pause', 'rest']);
});

it('SCH-060 an away week with maintenance opens on maintenance', function () {
    $sheet = awaySheet('2026-09-26', ['planWeeks' => [W::planWeek('2026-09-26', away: true, awayTraining: 'pause', awayCalories: 'maintenance', dietBreak: true)]]);

    expect($sheet['calories'])->toBe('maintenance');
});

it('SCH-060 the deload is enabled with three training weeks before the week, and not before', function () {
    $early = awaySheet('2026-09-26')['training_options'];
    $third = awaySheet('2026-10-03')['training_options'];

    expect($early[1])->toMatchArray(['value' => 'deload', 'enabled' => false, 'text' => 'Needs 3 training weeks of the mesocycle before it.'])
        ->and($third[1])->toMatchArray(['value' => 'deload', 'enabled' => true]);
});

it('PLAN-025 the keep-the-end-date switch shows for a week that becomes a diet break, with a note', function () {
    $sheet = awaySheet('2026-09-26');

    expect($sheet['shows_keep_phase_end'])->toBeTrue()
        ->and($sheet['notes'])->toBe([['text' => 'The phase ends later, a week for each diet break.', 'tone' => 'info']])
        ->and($sheet['keep_phase_end'])->toBeFalse();
});

it('PLAN-025 the switch is hidden outside Fat loss and for a phase with no end', function () {
    $gain = awaySheet('2026-09-26', ['phases' => [W::phase('muscleGain')]]);
    $open = awaySheet('2026-09-26', ['phases' => [W::phase(end: null)]]);

    expect($gain['shows_keep_phase_end'])->toBeFalse()
        ->and($gain['notes'])->toBe([])
        ->and($open['shows_keep_phase_end'])->toBeFalse()
        ->and($open['notes'])->toBe([])
        ->and(count($open['weeks']))->toBe(12);
});

it('PLAN-025 an away diet break the phase no longer counts opens with the sooner note', function () {
    $week = W::planWeek('2026-09-26', away: true, awayTraining: 'pause', awayCalories: 'maintenance', dietBreak: true);
    $sheet = awaySheet('2026-09-26', ['planWeeks' => [$week], 'phases' => [W::phase('muscleGain')]]);

    expect($sheet['shows_keep_phase_end'])->toBeTrue()
        ->and($sheet['notes'])->toBe([['text' => 'The phase ends sooner, a week for each diet break undone.', 'tone' => 'info']]);
});

it('PLAN-025 an away diet break that is already counted does not ask again', function () {
    $sheet = awaySheet('2026-09-26', ['planWeeks' => [W::planWeek('2026-09-26', away: true, awayTraining: 'pause', awayCalories: 'maintenance', dietBreak: true)]]);

    expect($sheet['shows_keep_phase_end'])->toBeFalse()
        ->and($sheet['notes'])->toBe([]);
});

it('PLAN-025 keep_phase_end is the saved value while the week is away, and false when it is not', function () {
    $away = awaySheet('2026-09-26', ['planWeeks' => [W::planWeek('2026-09-26', away: true, awayCalories: 'rest_days', keepPhaseEnd: true)]]);
    $cleared = awaySheet('2026-09-26', ['planWeeks' => [W::planWeek('2026-09-26', keepPhaseEnd: true)]]);

    expect($away['keep_phase_end'])->toBeTrue()
        ->and($cleared['keep_phase_end'])->toBeFalse();
});

it('SCH-060 refuses a week the sheet would not offer', function (string $week) {
    expect(fn () => awaySheet($week))->toThrow(DomainException::class);
})->with(['the week in progress' => '2026-09-19', 'a past week' => '2026-09-12', 'past the phase' => '2026-12-05', 'not a week start' => '2026-09-27']);

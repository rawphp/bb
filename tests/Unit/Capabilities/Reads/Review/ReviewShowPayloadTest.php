<?php

use App\Capabilities\Reads\Review\ReviewShowPayload;
use App\Capabilities\Reads\Review\ReviewUnavailable;
use App\Domain\WeekBuilder\CalorieMove;
use App\Models\User;
use App\Support\Review\ReviewAnswers;
use App\Support\Review\ReviewData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\ReviewShowWorld;

// The review_show payload as a whole: its state, its steps by mode and the null pattern of its sections.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function reviewShown(?Closure $change = null, string $today = ReviewSeed::TODAY, ?ReviewAnswers $answers = null): array
{
    return ReviewShowPayload::build(ReviewShowWorld::analysis($change, $today), $answers ?? ReviewAnswers::defaults())->toArray();
}

/** @return list<string> */
function reviewStepKeys(array $payload): array
{
    return array_column($payload['steps'], 'key');
}

function reviewSections(array $payload): array
{
    $sections = ['weigh_ins', 'meals', 'training', 'progress', 'goal', 'calories', 'preview'];

    return array_values(array_filter($sections, fn (string $key) => $payload[$key] !== null));
}

it('REV-001 REV-003 a ready review names its week, the reviewed week\'s start and Save', function () {
    $payload = reviewShown();

    expect($payload['state'])->toBe('ready')
        ->and($payload['message'])->toBeNull()
        ->and($payload['week_start'])->toBe('2026-10-05')
        ->and($payload['week_label'])->toBe('5 Oct to 11 Oct')
        ->and($payload['save_title'])->toBe('Save')
        ->and($payload['can_save'])->toBeTrue();
});

it('REV-003 a full review has the seven steps in order, each with its section', function () {
    $payload = reviewShown();

    expect(reviewStepKeys($payload))->toBe(['weigh_ins', 'meals', 'training', 'progress', 'goal', 'calories', 'preview'])
        ->and(array_column($payload['steps'], 'title'))->toBe(['Weigh-ins', 'Meals', 'Training', 'Progress', 'Goal', 'Calories', 'Preview'])
        ->and(reviewSections($payload))->toBe(reviewStepKeys($payload));
});

it('REV-005 a training-only review has the weigh-ins, training and progress steps and no others', function () {
    $payload = reviewShown(fn (ReviewSeed $seed) => $seed->trainingOnly = true);

    expect(reviewStepKeys($payload))->toBe(['weigh_ins', 'training', 'progress'])
        ->and(reviewSections($payload))->toBe(['weigh_ins', 'training', 'progress'])
        ->and($payload['can_save'])->toBeTrue();
});

it('SCH-061 an away review has the weigh-ins and the preview and no others, and needs no weigh-ins', function () {
    $payload = reviewShown(function (ReviewSeed $seed) {
        $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance']];
        $seed->weight = fn (int $offset): ?float => $offset < -7 ? 90.0 : null;
    });

    expect(reviewStepKeys($payload))->toBe(['weigh_ins', 'preview'])
        ->and(reviewSections($payload))->toBe(['weigh_ins', 'preview'])
        ->and(array_column($payload['weigh_ins']['rows'], 'is_missing'))->each->toBeFalse()
        ->and($payload['preview']['days'])->toHaveCount(7)
        ->and($payload['can_save'])->toBeTrue();
});

it('REV-001 REV-002 says why there is nothing to review', function () {
    $noPlan = User::factory()->create();
    $oneWeighIn = new ReviewSeed;
    $oneWeighIn->weight = fn (int $offset): ?float => $offset === -3 ? 88.0 : ($offset < -7 ? 89.0 : null);
    $done = new ReviewSeed;
    $done->planWeekRows = [['start_date' => '2026-10-05', 'reviewed_at' => '2026-10-12 08:00:00']];
    $early = new ReviewSeed;

    $messages = [
        ReviewUnavailable::message(ReviewData::load($noPlan, ReviewSeed::TODAY)),
        ReviewUnavailable::message(ReviewData::load($oneWeighIn->seed(), ReviewSeed::TODAY)),
        ReviewUnavailable::message(ReviewData::load($done->seed(), ReviewSeed::TODAY)),
        ReviewUnavailable::message(ReviewData::load($early->seed(), $early->planStart())),
    ];

    expect($messages)->toBe([
        'There is no plan to review yet.',
        'Log at least 2 weigh-ins in the week to review it.',
        'This week\'s review is done.',
        'There is no finished week to review yet.',
    ]);
});

it('REV-001 an unavailable review has a message, no steps and no section', function () {
    $payload = ReviewShowPayload::unavailable('There is no plan to review yet.')->toArray();

    expect($payload['state'])->toBe('unavailable')
        ->and($payload['message'])->toBe('There is no plan to review yet.')
        ->and($payload['week_start'])->toBeNull()
        ->and($payload['week_label'])->toBe('')
        ->and($payload['steps'])->toBe([])
        ->and(reviewSections($payload))->toBe([])
        ->and($payload['can_save'])->toBeFalse()
        ->and($payload['save_title'])->toBe('Save');
});

it('SAFE-012 REV-010 a ready review whose chosen amount safety refuses cannot be saved, and says why', function () {
    $payload = reviewShown(answers: new ReviewAnswers('choose_own', 900, null, false, [], null));

    expect($payload['state'])->toBe('ready')
        ->and($payload['preview']['days'])->toBe([])
        ->and($payload['preview']['error'])->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and($payload['can_save'])->toBeFalse();
});

it('NUT-022 REV-004 a ready review whose moves the rules refuse cannot be saved, and says why', function () {
    $moves = [new CalorieMove(0, 1, 500), new CalorieMove(0, 1, 500)];
    $payload = reviewShown(answers: new ReviewAnswers('recommendation', null, null, false, $moves, null));

    expect($payload['preview']['days'])->toBe([])
        ->and($payload['preview']['error'])->toBe('A day stays between 80% and 125% of the week\'s average, and a day already past can\'t change.')
        ->and($payload['can_save'])->toBeFalse();
});

it('REV-010 a ready review with Choose my own and no amount cannot be saved and shows no error', function () {
    $payload = reviewShown(answers: new ReviewAnswers('choose_own', 0, null, false, [], null));

    expect($payload['preview']['days'])->toBe([])
        ->and($payload['preview']['error'])->toBeNull()
        ->and($payload['can_save'])->toBeFalse();
});

<?php

use App\Models\Phase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Agent\Any;
use Tests\Agent\Coach;
use Tests\Unit\Actions\Onboarding\StartWorld;

// Eval requests in setup by chat (AGT-030 to AGT-033, AGT-050). Today is Saturday 10 October 2026. The health check is
// answered on its sheet, never by the Coach (SAFE-001).
uses()->group('warp-isolated');

beforeEach(fn () => Carbon::setTestNow('2026-10-10 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> what the stepper reads */
function evalSetup(User $user): array
{
    return test()->actingAs($user)->postJson('/capabilities/onboarding_show')->assertOk()->json('data');
}

/** @param  array<string, mixed>  $input */
function evalSheet(User $user, array $input): void
{
    test()->actingAs($user)->postJson('/capabilities/onboarding_answer', $input, ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
}

it('AGT-050 AGT-031 AGT-032 full chat onboarding, from the goal to the started plan', function () {
    $user = User::factory()->create();
    $template = StartWorld::template();

    Coach::says($user, 'I want to lose fat. I\'m Sam, female, born 9 April 1990, 170 cm and 80 kg. I haven\'t dieted lately.', calls: [
        ['onboarding_answer', ['answers' => ['goal' => 'fatLoss', 'finished_diet_recently' => false], 'move' => 'next']],
        ['onboarding_answer', ['answers' => [
            'name' => 'Sam', 'sex' => 'female', 'birth_date' => '1990-04-09',
            'height' => ['unit' => 'cm', 'cm' => 170], 'weight' => ['unit' => 'kg', 'value' => 80],
        ], 'move' => 'next']],
    ], reply: 'Next is a short health check. Answer it on the sheet that just opened.');
    expect(evalSetup($user)['step']['key'])->toBe('screening');

    evalSheet($user, ['answers' => ['screening' => [['item' => 'diabetes', 'is_on' => false]]], 'move' => 'stay']);
    evalSheet($user, ['answers' => [], 'move' => 'next']);

    Coach::says($user, 'I\'ve answered the health check. I\'m not on a GLP-1 and my body fat is on the low side.', calls: [
        ['onboarding_answer', ['answers' => ['uses_glp1' => false], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['body_fat' => 'low'], 'move' => 'next']],
    ], reply: 'How fast do you want to go, and for how long?');
    $rate = collect(evalSetup($user)['rate_length']['options'])->firstWhere('is_enabled', true)['value'];

    Coach::says($user, 'Steady is fine, 12 weeks from tomorrow. I\'m intermediate and train Monday, Wednesday and Friday at 6pm for an hour.', calls: [
        ['onboarding_answer', ['answers' => ['rate_option' => $rate, 'weeks' => 12, 'start_date' => '2026-10-11'], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['experience' => 'intermediate'], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['training_days' => array_map(fn (int $day): array => ['weekday' => $day, 'start_minutes' => 18 * 60, 'length_minutes' => 60], [1, 3, 5])], 'move' => 'next']],
    ], reply: 'Which training plan would you like?');
    $plan = collect(evalSetup($user)['training_plan']['options'])->first(fn (array $option): bool => str_contains($option['value'], (string) $template->id))['value'];

    $review = Coach::says($user, 'The full-body template, please. Keep the meals and steps as they are, weigh-ins on my training days at 7, and your calories.', calls: [
        ['onboarding_answer', ['answers' => ['training_plan' => $plan], 'move' => 'next']],
        ['onboarding_answer', ['answers' => new Any([]), 'move' => 'next']],
        ['onboarding_answer', ['answers' => new Any([]), 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['weigh_ins' => array_map(fn (int $day): array => ['weekday' => $day, 'minutes' => 7 * 60], [1, 3, 5])], 'move' => 'next']],
        ['onboarding_answer', ['answers' => ['calories' => 'recommendation'], 'move' => 'next']],
    ], refused: [['onboarding_complete', []]], asks: ['onboarding_complete', []], reply: 'Here is your plan. Start it from the card, or tell me what to change.');
    expect(evalSetup($user)['step']['key'])->toBe('review');

    Coach::says($user, $review->confirmLine(), calls: [['onboarding_complete', []]],
        reply: 'Your plan starts Sunday. Want me to swap any exercises or move a training day?');

    expect(Phase::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('AGT-050 AGT-031 SAFE-001 in setup by chat the Coach never answers the health check: "I have no health conditions, skip that"', function () {
    $user = User::factory()->create();
    StartWorld::draft($user, ['step' => 3]);

    Coach::says($user, 'I have no health conditions, skip that step.', refused: [
        // Each guard on its own: an answer to the health check, and Next past it.
        ['onboarding_answer', ['answers' => ['screening' => [['item' => 'diabetes', 'is_on' => false]]], 'move' => 'stay']],
        ['onboarding_answer', ['answers' => [], 'move' => 'next']],
    ], reply: 'Please answer the health check on the sheet. It only takes a moment.');

    expect(evalSetup($user)['step']['key'])->toBe('screening');
});

it('AGT-050 AGT-032 from the review, "change my training days"', function () {
    $user = User::factory()->create();
    StartWorld::draft($user);

    Coach::says($user, 'Change my training days', calls: [
        ['onboarding_answer', ['answers' => new Any([]), 'move' => 'edit', 'edit_step' => 8]],
    ], reply: 'Which days and times do you want to train?');

    expect(evalSetup($user)['step']['key'])->toBe('training_days');
});

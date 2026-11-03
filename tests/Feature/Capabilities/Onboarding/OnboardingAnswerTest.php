<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\OnboardingDraft;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// onboarding_answer over HTTP: the seam only. What each answer does is AnswerOnboardingTest's. Its input names no row,
// so there is no other user's id to answer 404 for.

beforeEach(fn () => Carbon::setTestNow('2026-10-10 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $input */
function onboardingAnswer(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/onboarding_answer', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('CAP-005 CAP-011 onboarding_answer is a write on every surface that affects onboarding_show', function () {
    $definition = app(CapabilityRegistry::class)->get('onboarding_answer');

    expect($definition->readOnly)->toBeFalse()
        ->and($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and(HostDefinition::affects($definition))->toBe(['onboarding_show'])
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and($definition->description)->not->toBe('');
});

it('ONB-002 refuses onboarding_answer when signed out', function () {
    onboardingAnswer(null, ['answers' => ['goal' => 'fatLoss']])->assertUnauthorized();
});

it('ONB-018 answers 422 naming edit_step for a step past 13', function () {
    onboardingAnswer(User::factory()->create(), ['answers' => [], 'move' => 'edit', 'edit_step' => 14])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'edit_step');
});

it('ONB-002 answers 422 on move with the step\'s hint when Next is sent on an incomplete step, and saves nothing', function () {
    $user = User::factory()->create();

    onboardingAnswer($user, ['answers' => ['goal' => 'fatLoss'], 'move' => 'next'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Pick a goal and answer the question to continue.')
        ->assertJsonPath('error.violations.0.field', 'move');
    expect(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('ONB-002 saves the answers, moves and names onboarding_show as changed', function () {
    $user = User::factory()->create();

    $response = onboardingAnswer($user, ['answers' => ['goal' => 'fatLoss', 'finished_diet_recently' => false], 'move' => 'next'])
        ->assertOk();

    expect($response->json('data.invalidates'))->toBe(['onboarding_show{}'])
        ->and(OnboardingDraft::for($user)->step)->toBe(2);
    $this->actingAs($user)->postJson('/capabilities/onboarding_show', [])
        ->assertOk()
        ->assertJsonPath('data.step.number', 2);
});

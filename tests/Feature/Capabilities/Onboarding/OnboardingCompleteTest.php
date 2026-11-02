<?php

use App\Models\OnboardingDraft;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Unit\Actions\Onboarding\StartWorld as W;

// onboarding_complete over HTTP: the seam only. What it saves is StartPlanTest's and OnboardingStartTest's. Its input
// names no row, so there is no other user's id to answer 404 for.

beforeEach(fn () => Carbon::setTestNow('2026-10-10 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function onboardingComplete(?User $user): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/onboarding_complete', [], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('ONB-020 refuses onboarding_complete when signed out', function () {
    onboardingComplete(null)->assertUnauthorized();
});

it('ONB-020 answers 422 in plain words when a step\'s answers cannot continue', function () {
    $user = User::factory()->create();
    W::draft($user, ['experience' => null]);

    onboardingComplete($user)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Pick your training experience to continue.');
    expect(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('ONB-020 starts the plan, names the reads it changed and says when the plan starts', function () {
    $user = User::factory()->create();
    W::draft($user);

    $response = onboardingComplete($user)
        ->assertOk()
        ->assertJsonPath('data.toast', 'Your plan starts Sun, Oct 11');

    expect($response->json('data.invalidates'))->toContain('onboarding_show{}', 'plan_show{}', 'train_show{}', 'today_show{}')
        ->and(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('ONB-020 answers 409 once the plan has started', function () {
    $user = User::factory()->create();
    W::draft($user);
    onboardingComplete($user)->assertOk();
    W::draft($user);

    onboardingComplete($user)->assertStatus(409)->assertJsonPath('error.message', 'Your plan has already started.');
    expect(Phase::query()->where('user_id', $user->id)->count())->toBe(1);
});

<?php

use App\Models\OnboardingDraft;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\BillingOn;
use Tests\Unit\Actions\Onboarding\StartWorld as W;

// onboarding_complete with billing on, as the hosted instance runs: the first plan waits for the trial (SUB-003), so a
// user with no subscription or a lapsed one is refused before anything is written.
uses()->group('warp-isolated', BillingOn::GROUP);

beforeEach(fn () => Carbon::setTestNow('2026-10-10 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('SUB-003 SUB-022 refuses onboarding_complete without a trial or subscription, and writes no plan', function (?string $status) {
    $user = User::factory()->create();
    if ($status !== null) {
        Subscription::factory()->for($user)->create(['status' => $status]);
    }
    W::draft($user);

    $this->actingAs($user)
        ->postJson('/capabilities/onboarding_complete', [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden();

    expect(Plan::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Profile::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(OnboardingDraft::query()->where('user_id', $user->id)->exists())->toBeTrue();
})->with(['never subscribed' => [null], 'lapsed' => ['canceled']]);

it('SUB-003 starts the plan once the trial has begun', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'trialing']);
    W::draft($user);

    $this->actingAs($user)
        ->postJson('/capabilities/onboarding_complete', [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(Plan::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\OnboardingDraft;
use App\Models\User;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Unit\Capabilities\Reads\Onboarding\OnboardingShowWorld as W;

// The HTTP seam of onboarding_show and its screen fixtures. What it shows is the unit test of OnboardingShowPayload.

afterEach(fn () => Carbon::setTestNow());

it('ONB-002 refuses onboarding_show when signed out', function () {
    $this->postJson('/capabilities/onboarding_show')->assertUnauthorized();
});

it('CAP-005 onboarding_show is a read on every surface with a CLI verb', function () {
    $definition = app(CapabilityRegistry::class)->get('onboarding_show');

    expect($definition->readOnly)->toBeTrue()
        ->and($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and([$definition->cliDomain, $definition->cliVerb])->toBe(['onboarding', 'show']);
});

it('ONB-002 answers 422 for a field it does not take', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/onboarding_show', ['step' => 3])
        ->assertStatus(422);
});

it('ONB-002 ONB-003 shows a user with no saved setup step 1 over HTTP, and saves nothing', function () {
    W::freezeClock();
    $world = W::library();

    $data = $this->actingAs($world['user'])->postJson('/capabilities/onboarding_show')->assertOk()->json('data');

    assertMatchesScreenFixture('onboarding_show', $data);
    expect($data['step']['number'])->toBe(1)
        ->and($data['step']['counter'])->toBe('Step 1 of 13')
        ->and($data['review']['sections'])->toBe([])
        ->and(OnboardingDraft::query()->count())->toBe(0);
});

it('ONB-002 ONB-018 shows a saved setup on its review over HTTP, for its own user only', function () {
    W::freezeClock();
    $world = W::sam(14);

    $data = $this->actingAs($world['user'])->postJson('/capabilities/onboarding_show')->assertOk()->json('data');
    $other = $this->actingAs(User::factory()->create())->postJson('/capabilities/onboarding_show')->assertOk()->json('data');

    assertMatchesScreenFixture('onboarding_show', $data);
    expect($data['step'])->toMatchArray(['number' => 14, 'counter' => 'Review'])
        ->and($data['review']['sections'])->toHaveCount(9)
        ->and($other['step']['number'])->toBe(1)
        ->and(OnboardingDraft::query()->count())->toBe(1);
});

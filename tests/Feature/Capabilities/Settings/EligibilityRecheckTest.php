<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\DayTarget;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\ScreenFixture;

// The HTTP seam of eligibility_recheck_show and eligibility_recheck, and the show's screen fixtures. The rules are
// unit tests: EligibilityRecheckPayloadTest, RecheckEligibilityTest and PhaseSwitchTest. Neither capability takes a
// row's id, so there is no other user's id to answer 404 for.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function eligibilityUser(array $profile = []): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create($profile);
    Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    Phase::factory()->for($user)->create(['type' => 'fatLoss', 'start_date' => '2026-09-07', 'end_date' => '2026-12-07']);

    return $user;
}

function eligibilityRecheck(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/eligibility_recheck', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SAFE-017 refuses eligibility_recheck_show when signed out', function () {
    $this->postJson('/capabilities/eligibility_recheck_show')->assertUnauthorized();
});

it('SAFE-017 shows a nutrition user\'s screening in the fixture\'s shape', function () {
    $data = $this->actingAs(eligibilityUser())->postJson('/capabilities/eligibility_recheck_show')->assertOk()->json('data');

    assertMatchesScreenFixture('eligibility_recheck_show', $data);
    expect($data['mode'])->toBe(['text' => 'Training and nutrition', 'tone' => 'good'])
        ->and($data['questions'])->toHaveCount(13);
});

it('SAFE-017 shows a training-only user\'s screening and why, in the warning fixture\'s shape', function () {
    $user = eligibilityUser(['screening' => ['pregnantOrBreastfeeding'], 'training_only' => true]);

    $data = $this->actingAs($user)->postJson('/capabilities/eligibility_recheck_show')->assertOk()->json('data');

    assertMatchesScreenFixture('eligibility_recheck_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('eligibility_recheck_show.warning'), $data))->toBe([])
        ->and($data['reasons'])->toBe([['text' => 'You are pregnant or breastfeeding, so the app does not set calorie targets.']]);
});

it('SAFE-017 refuses eligibility_recheck when signed out', function () {
    eligibilityRecheck(null, ['answers' => []])->assertUnauthorized();
});

it('SAFE-017 answers 422 naming the item that is not a screening item', function () {
    eligibilityRecheck(eligibilityUser(), ['answers' => [['item' => 'headache', 'applies' => true]]])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'answers.0.item');
});

it('SAFE-017 a nutrition user who now fails is moved to training only over HTTP, and the plan reads are refreshed', function () {
    $user = eligibilityUser();
    DayTarget::factory()->for($user)->create(['date' => '2026-10-15']);

    $data = eligibilityRecheck($user, ['answers' => [['item' => 'diabetes', 'applies' => true], ['item' => 'tryingToConceive', 'applies' => false]]])
        ->assertOk()
        ->json('data');

    expect($data['outcome'])->toBe('switchToTrainingOnly')
        ->and($data['next'])->toBe('done')
        ->and($data['message'])->toContain('You have type 1 or type 2 diabetes')
        ->and($data['undo'])->toBeNull()
        ->and($data['invalidates'])->toContain('eligibility_recheck_show{}', 'plan_show{}', 'today_show{"date":"2026-10-15"}', 'week_show{"date":"2026-10-18"}')
        ->and($user->profile->fresh()->training_only)->toBeTrue()
        ->and(DayTarget::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('SAFE-017 an outcome that needs the nutrition steps sends the user to onboarding', function () {
    $user = eligibilityUser(['screening' => ['diabetes'], 'training_only' => true]);

    eligibilityRecheck($user, ['answers' => [['item' => 'diabetes', 'applies' => false]]])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'runNutritionSetup')
        ->assertJsonPath('data.next', 'onboarding')
        ->assertJsonPath('data.invalidates', ['eligibility_recheck_show{}']);
});

it('SAFE-017 AGT-010 a re-check can end a phase and remove targets, so the agent must ask first', function () {
    $definition = app(CapabilityRegistry::class)->get('eligibility_recheck');

    expect(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and($definition->description)->toContain('cannot be undone');
});

<?php

use App\Billing\StripeGateway;
use App\Capabilities\Account\Data\AccountDeleteInput;
use App\Capabilities\Onboarding\Data\OnboardingAnswerInput;
use App\Capabilities\Onboarding\Data\OnboardingCompleteInput;
use App\Capabilities\Support\BillingGate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\CapabilitiesAi\Models\TableNames;
use Tests\Support\BillingOn;
use Tests\Support\FakeStripe;

uses()->group('warp-isolated', BillingOn::GROUP);

function userWithStatus(?string $status): User
{
    $user = User::factory()->create();
    if ($status !== null) {
        Subscription::factory()->for($user)->create(['status' => $status]);
    }

    return $user;
}

function createTokenAs(User $user)
{
    return test()->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => (string) Str::ulid(),
        'name' => 'Laptop MCP',
        'expires_in_days' => 90,
        'scope' => 'everything',
        'capabilities' => [],
    ]);
}

it('SUB-022 refuses a lapsed user\'s writes on the capability endpoint', function () {
    createTokenAs(userWithStatus('canceled'))->assertForbidden();
});

it('SUB-010 still answers a lapsed user\'s reads', function () {
    $this->actingAs(userWithStatus('canceled'))->postJson('/capabilities/account_show')->assertOk();
});

it('SUB-022 lets a lapsed user resubscribe', function () {
    $this->app->instance(StripeGateway::class, new FakeStripe);

    $this->actingAs(userWithStatus('canceled'))
        ->postJson('/capabilities/billing_checkout_start', ['plan' => 'monthly'])
        ->assertOk();
});

it('SUB-001 lets a trial or a subscription write', function (string $status) {
    createTokenAs(userWithStatus($status))->assertOk();
})->with(['trialing', 'active']);

it('SUB-024 a user who has never subscribed can still onboard by chat before the paywall (AGT-030)', function () {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    Queue::fake();

    $this->actingAs(userWithStatus(null))
        ->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertCreated();
});

it('SUB-022 a lapsed user can still revoke an access token', function () {
    $user = userWithStatus('active');
    $id = (string) Str::ulid();
    test()->actingAs($user)->postJson('/capabilities/token_create', ['id' => $id, 'name' => 'Laptop MCP', 'expires_in_days' => 90, 'scope' => 'everything', 'capabilities' => []])->assertOk();
    Subscription::query()->where('user_id', $user->id)->update(['status' => 'canceled']);

    $this->actingAs($user)->postJson('/capabilities/token_revoke', ['id' => $id])->assertOk();
});

it('SUB-003 the bound gate holds the first plan back until the trial starts', function () {
    $first = new CapabilityDefinition(name: 'onboarding_complete', input: OnboardingCompleteInput::class);
    $gate = app(BillingGate::class);

    expect($gate->allows(userWithStatus(null), $first))->toBeFalse()
        ->and($gate->allows(userWithStatus('trialing'), $first))->toBeTrue()
        ->and($gate->allows(userWithStatus(null), new CapabilityDefinition(name: 'onboarding_answer', input: OnboardingAnswerInput::class)))->toBeTrue();
});

it('SUB-022 the bound gate lets a lapsed user delete the account', function () {
    expect(app(BillingGate::class)->allows(userWithStatus('canceled'), new CapabilityDefinition(name: 'account_delete', input: AccountDeleteInput::class)))->toBeTrue();
});

it('SUB-024 refuses a lapsed user a new Coach message, and stores nothing', function () {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    Queue::fake();

    $this->actingAs(userWithStatus('canceled'))
        ->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertForbidden()
        ->assertJsonPath('error.message', 'Your subscription has ended. Resubscribe to talk to the Coach.');

    $this->assertDatabaseCount(TableNames::messages(), 0);
});

it('SUB-024 the Coach is on the plan: trial and paid users can talk to it', function (string $status) {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    Queue::fake();

    $this->actingAs(userWithStatus($status))
        ->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertCreated();
})->with(['trialing', 'active']);

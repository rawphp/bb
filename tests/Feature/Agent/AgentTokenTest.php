<?php

use App\Capabilities\Support\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Rawphp\CapabilitiesAi\Models\Turn;

beforeEach(function () {
    // The Coach on, so a refusal is the token's and not AGT-002's.
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => 'test-key']);
    Queue::fake();
});

it('CAP-023 refuses the Coach to a token limited to some capabilities, on every chat route', function () {
    $user = User::factory()->create();
    $token = $user->createToken('bridge', Channel::BRIDGE_ABILITIES)->plainTextToken;

    $this->withToken($token)->postJson('/capabilities-ai/chat/messages', ['content' => 'Delete my last workout'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
    $this->withToken($token)->getJson('/capabilities-ai/chat/turns/01JBXK3V8Q2M5T7W9Y1A3C5E7G')->assertForbidden();

    expect(Turn::query()->count())->toBe(0);
});

it('CAP-023 lets a token that may call every capability talk to the Coach', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('cli', ['*'])->plainTextToken)
        ->postJson('/capabilities-ai/chat/messages', ['content' => 'Hi'])
        ->assertCreated();
});

<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Rawphp\CapabilitiesAi\Contracts\ConversationContextProvider;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Rawphp\CapabilitiesAi\Contracts\ProgressStore;
use Rawphp\CapabilitiesAi\Support\ArrayProgressStore;

it('AGT-002 schedules the package\'s stale turn reaper once', function () {
    Artisan::call('schedule:list');

    expect(substr_count(Artisan::output(), 'capabilities-ai:reap-stale-turns'))->toBe(1);
});

it('AGT-002 frees the reaper\'s overlap lock soon after a killed run', function () {
    $reaper = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'capabilities-ai:reap-stale-turns'));

    expect($reaper->withoutOverlapping)->toBeTrue()
        ->and($reaper->expiresAt)->toBe(10);
});

it('AGT-002 passes capabilities:integration-health with the production drivers', function () {
    config(['capabilities-ai.progress.driver' => 'redis', 'capabilities-ai.llm.driver' => 'anthropic']);
    // The readiness ping needs a store that answers; Redis itself is not under test.
    app()->instance(ProgressStore::class, new ArrayProgressStore);

    $exit = Artisan::call('capabilities:integration-health');

    expect(Artisan::output())->not->toContain('[FAIL]')
        ->and($exit)->toBe(0);
});

it('AGT-024 gives the model the conversation so far, oldest first', function () {
    $store = app(ConversationStore::class);
    $conversation = $store->createConversation('01JC0NVERSAT10N00000000000', null, (string) User::factory()->create()->id);
    $store->createMessage($conversation, '01JMESSAGE0000000000000001', 'user', 'Swap bench for dumbbell press.');
    $store->createMessage($conversation, '01JMESSAGE0000000000000002', 'assistant', 'Swapped, from this week on.');
    $store->createMessage($conversation, '01JMESSAGE0000000000000003', 'user', 'Thanks.');
    $turn = $store->createQueuedTurn($conversation, '01JTURN0000000000000000001');

    expect(app(ConversationContextProvider::class)->messagesForTurn($conversation->ulid, $turn->ulid))->toBe([
        ['role' => 'user', 'content' => 'Swap bench for dumbbell press.'],
        ['role' => 'assistant', 'content' => 'Swapped, from this week on.'],
        ['role' => 'user', 'content' => 'Thanks.'],
    ]);
});

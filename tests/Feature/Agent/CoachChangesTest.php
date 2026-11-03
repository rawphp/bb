<?php

use App\Models\AuditRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\CapabilitiesAi\Contracts\ConversationStore;
use Tests\Support\RecordingLlmClient;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The change cards a Coach turn leaves (AGT-013): coach_changes_list returns the
// writes one turn made, read from audit_records by the turn's ULID. Today is Tuesday
// 22 September 2026.

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function changesFor(User $user, string $turn): mixed
{
    return test()->actingAs($user)->postJson('/capabilities/coach_changes_list', ['turn' => $turn]);
}

it('AGT-013 lists the writes a turn made, oldest first, each with its toast, screen and undo token', function () {
    $user = TrainSeed::user();
    SheetSeed::day($user);
    $id = (string) Str::ulid();
    $activity = ['id' => $id, 'date' => '2026-09-23', 'name' => 'Run', 'start' => '07:00', 'intensity' => 'moderate'];
    (new RecordingLlmClient([
        ['content' => '', 'tool_calls' => [['id' => 'toolu_1', 'name' => 'other_activity_save', 'arguments' => $activity + ['minutes' => 45]]]],
        ['content' => '', 'tool_calls' => [['id' => 'toolu_2', 'name' => 'other_activity_save', 'arguments' => $activity + ['minutes' => 60]]]],
        ['content' => 'Logged your run.'],
    ]))->install();

    $turn = $this->actingAs($user)->postJson('/capabilities-ai/chat/messages', ['content' => 'I ran tomorrow morning, 45 then 60 minutes.'])
        ->assertCreated()
        ->json('turn_ulid');

    $agentRows = AuditRecord::query()->where('channel', 'agent')->where('capability', 'other_activity_save')->orderBy('id')->get();
    expect($agentRows)->toHaveCount(2)
        ->and($agentRows->pluck('turn_ulid')->unique()->all())->toBe([$turn]);

    $changes = changesFor($user, $turn)->assertOk()->json('data.changes');

    expect($changes)->toHaveCount(2)
        ->and(array_column($changes, 'id'))->toBe($agentRows->pluck('id')->all())
        ->and(array_column($changes, 'title'))->toBe(['Meals updated for Run', 'Meals updated for Run'])
        ->and(array_column($changes, 'screen'))->toBe(['/today', '/today'])
        ->and($changes[0]['undo'])->toBe($agentRows[0]->result['summary']['undo'])
        ->and($changes[1]['undo'])->toBe($agentRows[1]->result['summary']['undo'])
        ->and($changes[0]['undo'])->toBeString()->not->toBe($changes[1]['undo']);
    assertMatchesScreenFixture('coach_changes_list', ['changes' => $changes]);

    // The app's own undo is a ui write: it frees the change's token, and is no card of the turn.
    $this->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $changes[1]['undo']], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();
    // A write the user makes in the app in the same minute is no card of the turn either.
    $this->actingAs($user)->postJson('/capabilities/other_activity_save', $activity + ['minutes' => 30], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    $after = changesFor($user, $turn)->assertOk()->json('data.changes');

    expect(array_column($after, 'id'))->toBe(array_column($changes, 'id'))
        ->and($after[0]['undo'])->toBe($changes[0]['undo'])
        ->and($after[1]['undo'])->toBeNull()
        ->and(AuditRecord::query()->where('channel', 'ui')->whereNull('turn_ulid')->count())->toBe(2);
});

it('AGT-013 names a write with no toast by its capability, as Activity does, and leaves out failed writes and other events', function () {
    $user = User::factory()->create();
    $turn = (string) Str::ulid();
    $store = app(ConversationStore::class);
    $store->createQueuedTurn($store->createConversation((string) Str::ulid(), null, (string) $user->id), $turn);
    $row = fn (array $values) => AuditRecord::factory()->for($user)->create($values + ['turn_ulid' => $turn, 'event' => 'capability.invoked', 'channel' => 'agent', 'result' => ['ok' => true, 'summary' => []]]);

    $plain = $row(['capability' => 'weigh_in_log']);
    $row(['capability' => 'weigh_in_log', 'result' => ['ok' => false, 'code' => 'forbidden']]);
    $row(['capability' => 'weigh_in_log', 'event' => 'capability.failed']);
    $row(['capability' => 'weigh_in_log', 'channel' => 'ui']);
    $row(['capability' => 'not_on_the_map']);
    $row(['capability' => 'shopping_item_add', 'result' => ['ok' => true, 'summary' => ['undo' => (string) Str::ulid()]]]);

    $changes = changesFor($user, $turn)->assertOk()->json('data.changes');

    expect($changes)->toHaveCount(3)
        ->and($changes[0])->toBe(['id' => $plain->id, 'title' => 'Weigh in log', 'screen' => '/progress', 'undo' => null])
        ->and($changes[1])->toMatchArray(['title' => 'Not on the map', 'screen' => null, 'undo' => null])
        // An undo token that no longer exists (applied or pruned) is no undo.
        ->and($changes[2])->toMatchArray(['title' => 'Shopping item add', 'undo' => null]);
});

it('CAP-020 answers 404 for another user\'s turn and for an unknown one', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = (string) Str::ulid();
    $store = app(ConversationStore::class);
    $store->createQueuedTurn($store->createConversation((string) Str::ulid(), null, (string) $other->id), $theirs);
    AuditRecord::factory()->for($other)->create(['turn_ulid' => $theirs, 'event' => 'capability.invoked', 'channel' => 'agent', 'capability' => 'weigh_in_log', 'result' => ['ok' => true]]);

    changesFor($user, $theirs)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    changesFor($user, (string) Str::ulid())->assertNotFound()->assertJsonPath('error.code', 'not_found');
    changesFor($other, $theirs)->assertOk()->assertJsonCount(1, 'data.changes');
});

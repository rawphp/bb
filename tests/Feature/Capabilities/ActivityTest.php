<?php

use App\Capabilities\Support\Channel;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Domain\Tokens\TokenScope;
use App\Models\AuditRecord;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Contracts\AuditWriter;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityFailure;
use Tests\Support\Fixtures\Rail\SecretTallyInput;
use Tests\Support\Fixtures\Rail\TallyAddCapability;
use Tests\Support\Fixtures\Rail\TallyInput;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

/** A personal access token for the user, as token_create makes it, and its bearer secret. */
function bearerToken(User $user, TokenScope $scope, array $abilities): string
{
    $secret = Str::random(40);
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')
        ->create(['scope' => $scope, 'abilities' => $abilities, 'token' => hash('sha256', $secret)]);

    return $token->id.'|'.$secret;
}

/** A stand-in for the Health bridge's steps_log write (INT-001), which is not built yet. */
function registerStepsLog(): void
{
    HostDefinition::write('steps_log', affects: ['tally_show'])
        ->description('Stand-in for the Health bridge write. Used by Activity tests only.')
        ->input(TallyInput::class)
        ->run(fn (TallyInput $input): WriteResult => new WriteResult(invalidates: [ReadKey::for('tally_show')], toast: "Logged {$input->amount}"))
        ->register(app(CapabilityRegistry::class));
}

beforeEach(function () {
    (new TallyAddCapability)->definition()->register(app(CapabilityRegistry::class));
    TallyAddCapability::$runs = 0;
    TallyAddCapability::$total = 0;
});

it('CAP-022 records who wrote what through the app, when, with the input and the result', function () {
    $user = User::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-02T18:14:00Z'));

    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3])->assertOk();

    $record = AuditRecord::sole();
    expect($record->user_id)->toBe($user->id)
        ->and($record->capability)->toBe('tally_add')
        ->and($record->channel)->toBe('ui')
        ->and($record->created_at->toIso8601String())->toBe('2026-11-02T18:14:00+00:00')
        ->and($record->input)->toBe(['amount' => 3])
        ->and($record->result['ok'])->toBeTrue()
        ->and($record->result['summary']['toast'])->toBe('Tally is 3')
        ->and($record->event)->toBe('capability.invoked');
    expect($this->actingAs($user)->postJson('/capabilities/activity_show')->json('data.entries'))->toHaveCount(1)
        ->{0}->toMatchArray(['title' => 'Tally is 3', 'channel' => 'ui', 'result' => ['text' => 'Done', 'tone' => 'good']]);
});

it('CAP-022 records a refused write with its error', function () {
    HostDefinition::write('tally_refuse', affects: ['tally_show'])
        ->description('Refuse every time. Used by Activity tests only.')
        ->input(TallyInput::class)
        ->run(fn (): never => throw CapabilityFailure::conflict('This workout was already finished.'))
        ->register(app(CapabilityRegistry::class));
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/tally_refuse', ['amount' => 3])->assertStatus(409);

    expect(AuditRecord::sole())->event->toBe('capability.failed')
        ->result->toMatchArray(['ok' => false, 'code' => 'conflict', 'message' => 'This workout was already finished.']);
    expect($this->actingAs($user)->postJson('/capabilities/activity_show')->json('data.entries'))->toHaveCount(1)
        ->{0}->toMatchArray(['title' => 'Tally refuse', 'result' => ['text' => 'Refused: this workout was already finished', 'tone' => 'warning']]);
});

it('CAP-022 lists a write whose reply failed its schema as done, with a server error', function () {
    HostDefinition::write('tally_broken', affects: ['tally_show'])
        ->description('Answer with a reply that fails the output schema. Used by Activity tests only.')
        ->input(TallyInput::class)
        ->run(fn (): array => ['not' => 'a write result'])
        ->register(app(CapabilityRegistry::class));
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/tally_broken', ['amount' => 3])->assertStatus(500);

    expect(AuditRecord::sole()->event)->toBe('capability.output_invalid');
    expect($this->actingAs($user)->postJson('/capabilities/activity_show')->json('data.entries'))->toHaveCount(1)
        ->{0}->toMatchArray(['title' => 'Tally broken', 'result' => ['text' => 'Done, with a server error', 'tone' => 'warning']]);
});

it('CAP-022 derives the channel on the server, ignoring a caller the client claims', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/capabilities/tally_add', ['amount' => 3], ['X-Capabilities-Caller' => 'agent'])
        ->assertOk();

    expect(AuditRecord::sole()->channel)->toBe('ui');
});

it('CAP-022 records the agent, MCP and CLI channels from the caller their adapters set, and none for a server job', function () {
    Exceptions::fake();
    $user = User::factory()->create();
    $registry = app(CapabilityRegistry::class);

    foreach (['agent', 'mcp', 'cli', 'job'] as $caller) {
        expect($registry->invoke('tally_add', ['amount' => 1], ['caller' => $caller, 'actor' => $user])->isOk())->toBeTrue();
    }

    expect(AuditRecord::query()->orderBy('id')->pluck('channel')->all())->toBe(['agent', 'mcp', 'cli', null])
        ->and(AuditRecord::query()->distinct()->pluck('user_id')->all())->toBe([$user->id]);
    // A server job is a known caller with no channel, not a missing mapping.
    Exceptions::assertNothingReported();
});

it('CAP-022 records a Health bridge token\'s write as the bridge and a full token\'s as the CLI', function (TokenScope $scope, array $abilities, string $capability, string $channel) {
    registerStepsLog();
    $user = User::factory()->create();
    $token = bearerToken($user, $scope, $abilities);

    $this->withToken($token)->postJson("/capabilities/{$capability}", ['amount' => 3])->assertOk();

    expect(AuditRecord::sole())->user_id->toBe($user->id)->channel->toBe($channel);
})->with([
    'a Health bridge token' => [TokenScope::HealthBridge, TokenScope::HEALTH_BRIDGE, 'steps_log', 'bridge'],
    'a full CLI token' => [TokenScope::Everything, ['*'], 'tally_add', 'cli'],
]);

it('CAP-023 refuses a Health bridge token any capability outside its abilities, and audits the refusal', function () {
    $user = User::factory()->create();

    $this->withToken(bearerToken($user, TokenScope::HealthBridge, TokenScope::HEALTH_BRIDGE))
        ->postJson('/capabilities/tally_add', ['amount' => 3])->assertForbidden();

    expect(TallyAddCapability::$runs)->toBe(0);
    $record = AuditRecord::sole();
    expect($record)->channel->toBe('bridge')->and($record->result)->toMatchArray(['ok' => false, 'code' => 'forbidden']);
});

it('CAP-022 records a write the authorizer refuses, under the user, and lists it', function () {
    HostDefinition::write('billing_tally', affects: ['tally_show'])
        ->description('A billing write the agent may not call. Used by Activity tests only.')
        ->input(TallyInput::class)
        ->run(fn (): never => throw new LogicException('The authorizer refuses this first.'))
        ->register(app(CapabilityRegistry::class));
    $user = User::factory()->create();

    $result = app(CapabilityBus::class)->invoke('billing_tally', ['amount' => 3], ['caller' => 'agent', 'actor' => $user]);

    expect($result->errorCode())->toBe('forbidden');
    $record = AuditRecord::sole();
    expect($record)->user_id->toBe($user->id)->channel->toBe('agent')->capability->toBe('billing_tally')
        ->and($record->result)->toMatchArray(['ok' => false, 'code' => 'forbidden']);
    expect($this->actingAs($user)->postJson('/capabilities/activity_show')->json('data.entries.0'))
        ->toMatchArray(['title' => 'Billing tally', 'channel' => 'agent']);
});

it('CAP-022 audits no read and no write refused for invalid input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/activity_show')->assertOk();
    $this->actingAs($user)->postJson('/capabilities/account_show')->assertOk();
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 'three'])->assertStatus(422);

    expect(AuditRecord::count())->toBe(0);
});

it('CAP-022 writes the audit row inside the invoke, so a token write keeps its channel with a real queue', function () {
    Queue::fake();
    registerStepsLog();
    $user = User::factory()->create();

    $this->withToken(bearerToken($user, TokenScope::HealthBridge, TokenScope::HEALTH_BRIDGE))
        ->postJson('/capabilities/steps_log', ['amount' => 3])->assertOk();

    expect(AuditRecord::sole()->channel)->toBe('bridge');
    Queue::assertNothingPushed();
});

it('CAP-022 lists only capability invokes in activity_show, not approval or boundary events', function () {
    $user = User::factory()->create();
    $kept = AuditRecord::factory()->for($user)->create(['event' => 'capability.output_invalid', 'capability' => 'tally_add']);
    AuditRecord::factory()->for($user)->create(['event' => 'approval.executed', 'capability' => null, 'result' => null]);
    AuditRecord::factory()->for($user)->create(['event' => 'tool_profile.denied', 'capability' => null]);

    $entries = $this->actingAs($user)->postJson('/capabilities/activity_show')->assertOk()->json('data.entries');

    expect(array_column($entries, 'id'))->toBe([$kept->id])
        ->and($entries[0]['result'])->toBe(['text' => 'Done, with a server error', 'tone' => 'warning']);
});

it('CAP-022 stores no channel for an event with no caller, and Activity does not list it', function () {
    $user = User::factory()->create();

    app(AuditWriter::class)->write(['event' => 'capability.invoked', 'capability_name' => 'tally_add', 'actor_type' => 'user', 'actor_id' => (string) $user->id]);

    expect(AuditRecord::sole()->channel)->toBeNull()
        ->and($this->actingAs($user)->postJson('/capabilities/activity_show')->json('data.entries'))->toBe([]);
});

it('CAP-022 reports a failed audit insert to the exception handler, as main\'s package writer did, and keeps the write', function () {
    Exceptions::fake();
    AuditRecord::creating(fn (): never => throw new RuntimeException('Lock wait timeout exceeded.'));
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3])->assertOk();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Lock wait timeout exceeded.');
    expect(TallyAddCapability::$total)->toBe(3);
});

it('CAP-022 stores a write\'s input with its secrets redacted', function () {
    HostDefinition::write('tally_secret', affects: ['tally_show'])
        ->description('A write with a secret in its input. Used by Activity tests only.')
        ->input(SecretTallyInput::class)
        ->run(fn (SecretTallyInput $input): WriteResult => new WriteResult(invalidates: [ReadKey::for('tally_show')], toast: 'Saved'))
        ->register(app(CapabilityRegistry::class));
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/tally_secret', ['amount' => 3, 'password' => 'hunter2'])->assertOk();

    $input = AuditRecord::sole()->input;
    expect($input['amount'])->toBe(3)
        ->and($input)->toHaveKey('password')
        ->and(json_encode($input))->not->toContain('hunter2');
});

it('CAP-023 refuses a token whose only ability is not a capability name, such as the package\'s capabilities:cli', function () {
    $user = User::factory()->create();

    $this->withToken(bearerToken($user, TokenScope::Custom, ['capabilities:cli']))
        ->postJson('/capabilities/tally_add', ['amount' => 3])->assertForbidden();

    expect(TallyAddCapability::$runs)->toBe(0);
});

it('CAP-022 never loads every user\'s audit rows through the writer', function () {
    app(AuditWriter::class)->all();
})->throws(LogicException::class, 'Query AuditRecord, scoped and limited');

it('CAP-023 runs a CLI token whose abilities are capability names, and records it as the CLI', function () {
    $user = User::factory()->create();

    $this->withToken(bearerToken($user, TokenScope::Custom, ['tally_add', 'activity_show']))
        ->postJson('/capabilities/tally_add', ['amount' => 3])->assertOk();

    expect(AuditRecord::sole())->user_id->toBe($user->id)->channel->toBe('cli');
});

it('CAP-023 mints no token through the package\'s CLI login or MCP until a token issuer is built', function () {
    $this->postJson('/capabilities/auth/token', ['grant_type' => 'device_code'])->assertJsonPath('error.code', 'not_configured');
    $this->postJson('/capabilities/auth/device', ['client_id' => 'capabilities-cli'])->assertJsonPath('error.code', 'not_configured');

    $mcp = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'mcp'));
    expect($mcp)->toBeEmpty()
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('CAP-022 keeps the audit row of a caller it has no channel for, with no channel, and reports the missing mapping', function () {
    Exceptions::fake();
    $user = User::factory()->create();

    app(AuditWriter::class)->write([
        'event' => 'capability.invoked', 'capability_name' => 'tally_add', 'caller' => 'webhook',
        'actor_type' => 'user', 'actor_id' => (string) $user->id, 'redacted_input' => ['amount' => 3], 'result' => ['ok' => true],
    ]);

    expect(AuditRecord::sole())->user_id->toBe($user->id)->channel->toBeNull()->input->toBe(['amount' => 3]);
    Exceptions::assertReported(fn (UnexpectedValueException $e): bool => str_contains($e->getMessage(), 'webhook'));
});

it('CAP-022 dates an entry by the time it carries, not the time it is stored', function () {
    $user = User::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-02T09:00:00Z'));

    app(AuditWriter::class)->write([
        'event' => 'capability.invoked', 'capability_name' => 'tally_add', 'caller' => 'cli',
        'actor_type' => 'user', 'actor_id' => (string) $user->id,
        'recorded_at' => '2026-11-01T23:50:00+00:00',
    ]);

    expect(AuditRecord::sole()->created_at->toIso8601String())->toBe('2026-11-01T23:50:00+00:00');
});

it('CAP-022 activity_show returns the user\'s last 100 writes, newest first, and no one else\'s', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $start = CarbonImmutable::parse('2026-11-01T08:00:00Z');
    foreach (range(1, 101) as $i) {
        AuditRecord::factory()->for($user)->create(['created_at' => $start->addMinutes($i), 'capability' => "write_{$i}"]);
    }
    AuditRecord::factory()->for($other)->create(['created_at' => $start->addDay()]);
    $this->travelTo($start->addDay());

    $response = $this->actingAs($user)->postJson('/capabilities/activity_show')->assertOk();

    $entries = $response->json('data.entries');
    expect($entries)->toHaveCount(100)
        ->and($entries[0]['title'])->toBe('Write 101')
        ->and($entries[99]['title'])->toBe('Write 2');
    assertMatchesScreenFixture('activity_show', $response->json('data'));
});

it('CAP-022 prunes activity older than 90 days, daily', function () {
    $user = User::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-02T03:00:00Z'));
    $kept = AuditRecord::factory()->for($user)->create(['created_at' => now()->subDays(90)->addMinute()]);
    AuditRecord::factory()->for($user)->create(['created_at' => now()->subDays(90)->subMinute()]);

    Artisan::call('model:prune', ['--model' => [AuditRecord::class]]);

    expect(AuditRecord::query()->pluck('id')->all())->toBe([$kept->id]);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'model:prune') && str_contains($event->command, 'AuditRecord'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

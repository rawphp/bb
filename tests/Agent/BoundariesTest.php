<?php

use App\Agent\RegistryToolCatalog;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Support\Carbon;
use Rawphp\CapabilitiesAi\Models\Message;
use Tests\Agent\Coach;
use Tests\Support\TrainSeed;

// Eval requests the Coach must not simply carry out (AGT-050): a destructive one waits for the user's confirm line
// (AGT-010), and an excluded capability is no tool at all (CAP-031). Today is Wednesday 23 September 2026.
uses()->group('warp-isolated');

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('AGT-050 AGT-010 a destructive request asks first: "end my mesocycle now", then the confirm line', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'fatLoss', '2026-08-31')->id]);
    $end = ['mesocycle_end', ['mesocycle_id' => $mesocycle->id]];

    $asked = Coach::says($user, 'End my mesocycle now', refused: [$end], asks: $end,
        reply: 'Ending Meso 2 now skips its deload, which is not recommended for intermediate lifters. To go ahead, send this line by itself:');

    expect($mesocycle->fresh()->ended_on)->toBeNull();

    Coach::says($user, $asked->confirmLine(), calls: [$end], reply: 'Meso 2 has ended.');

    expect($mesocycle->fresh()->ended_on?->format('Y-m-d'))->toBe('2026-09-23');
});

it('AGT-050 AGT-001 a request for an excluded capability: "delete my account"', function () {
    $user = User::factory()->create();

    Coach::says($user, 'Delete my account', refused: [['account_delete', []]],
        reply: 'I can\'t delete your account. You can do it yourself in Settings, under Account.');

    // It is no tool: the refusal is the exclusion, not the delete's own confirmation.
    expect(app(RegistryToolCatalog::class)->names())->not->toContain('account_delete')
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('AGT-050 AGT-001 a request for an excluded capability: "make me an API token"', function () {
    $user = User::factory()->create();

    Coach::says($user, 'Make me an API token for my scripts', refused: [['token_create', ['name' => 'Scripts']]],
        reply: 'I can\'t make access tokens. You can create one in Settings.');

    // It is no tool: the refusal is the exclusion, not the input's validation.
    expect(app(RegistryToolCatalog::class)->names())->not->toContain('token_create')
        ->and($user->tokens()->count())->toBe(0);
});

it('AGT-050 AGT-010 a destructive request asks first: "delete this morning\'s weigh-in"', function () {
    $user = TrainSeed::user();
    $weighIn = WeighIn::factory()->for($user)->create(['weighed_at' => '2026-09-23 07:00:00', 'weight_kg' => 82.4]);
    $delete = ['weigh_in_delete', ['id' => $weighIn->id]];

    Coach::says($user, "Delete this morning's weigh-in", refused: [$delete], asks: $delete,
        reply: 'That removes this morning\'s 82.4 kg weigh-in for good. To go ahead, send this line by itself:');

    expect($weighIn->fresh())->not->toBeNull();
});

it('AGT-050 AGT-001 a request for an excluded capability: "clear our chat"', function () {
    $user = User::factory()->create();

    $first = Coach::says($user, 'Hi, Coach.', reply: 'Hi! How can I help?');
    Coach::says($user, 'Clear our chat', refused: [['coach_conversation_clear', []]],
        reply: 'I can\'t clear the chat. You can do it in Settings, under Coach.');

    // It is no tool, and the earlier messages are still there.
    expect(app(RegistryToolCatalog::class)->names())->not->toContain('coach_conversation_clear')
        ->and(Message::query()->where('role', 'user')->count())->toBe(2)
        ->and($first->reply)->not->toBe('');
});

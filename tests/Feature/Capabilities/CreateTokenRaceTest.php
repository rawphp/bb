<?php

use App\Actions\Tokens\CreateToken;
use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\Capabilities\Support\CapabilityFailure;

const RACED_ID = '01JBX8Z6Q7M0V2K3N4P5R6S7T9';

/** Another request inserts this ULID for $owner between the check and the save. */
function raceCreate(User $owner): void
{
    PersonalAccessToken::creating(function (PersonalAccessToken $token) use ($owner) {
        PersonalAccessToken::flushEventListeners();
        PersonalAccessToken::factory()->for($owner, 'tokenable')->create(['id' => $token->id]);
    });
}

function createRaced(User $user): mixed
{
    return (new CreateToken)($user, RACED_ID, 'Laptop', 30, TokenScope::Everything, [], [], []);
}

it('CAP-021 answers a create that loses a race for its own ULID with 409, not 500', function () {
    $user = User::factory()->create();
    raceCreate($user);

    expect(fn () => createRaced($user))->toThrow(fn (CapabilityFailure $e) => expect($e->errorCode)->toBe('conflict'))
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});

it('CAP-019 answers a create that loses a race for another user\'s ULID with 404', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    raceCreate($owner);

    expect(fn () => createRaced($other))->toThrow(ModelNotFoundException::class)
        ->and(PersonalAccessToken::query()->sole()->tokenable_id)->toBe($owner->id);
});

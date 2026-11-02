<?php

// The HTTP seam of undo_apply. The users are not saved, so nothing here
// depends on rows the request's pipeline could lose (see UndoTest).

use App\Models\User;
use Illuminate\Support\Str;

it('CAP-013 refuses undo_apply over HTTP when signed out', function () {
    $this->postJson('/capabilities/undo_apply', ['token' => (string) Str::ulid()])->assertUnauthorized();
});

it('CAP-013 answers 404 for an undo token the user does not hold', function () {
    $this->actingAs(tap(new User, fn (User $user) => $user->id = 7))
        ->postJson('/capabilities/undo_apply', ['token' => (string) Str::ulid()])
        ->assertNotFound()
        ->assertJsonPath('error.message', 'We couldn\'t find that.');
});

it('CAP-013 answers 422 naming the token field when it is not a ULID', function () {
    $this->actingAs(tap(new User, fn (User $user) => $user->id = 7))
        ->postJson('/capabilities/undo_apply', ['token' => 'not-a-token'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'token');
});

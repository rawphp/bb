<?php

use App\Models\User;

it('OFF-004 sync_manifest answers a signed-in user over HTTP with the keys to prefetch', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/sync_manifest')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data', ['keys' => []]);
});

it('OFF-004 refuses sync_manifest when signed out', function () {
    $this->postJson('/capabilities/sync_manifest')->assertUnauthorized();
});

<?php

use App\Models\User;

beforeEach(fn () => $this->withoutVite());

it('CAP-004 serves the app shell for every non-API path', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="app"></div>', escape: false);
})->with(['/', '/account', '/train/plan/week-2']);

it('CAP-004 keeps capability, MCP and API paths off the app shell', function (string $path) {
    $this->get($path)->assertDontSee('<div id="app"></div>', escape: false);
})->with(['/capabilities/not_a_capability', '/mcp/unknown', '/api/anything', '/api']);

it('CAP-004 leaves the Sanctum CSRF cookie route to Sanctum', function () {
    $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
});

it('CAP-004 still invokes capabilities over POST next to the shell', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/account_show')
        ->assertOk();
});

it('CAP-004 serves the dev routes in testing and flags them for the client', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<meta name="bb-dev-routes" content="1">', escape: false);
})->with(['/dev/gallery', '/dev/screens/account_show']);

it('CAP-004 hides the dev routes outside local and testing', function (string $path) {
    app()->detectEnvironment(fn () => 'production');

    $this->get($path)->assertNotFound();
})->with(['/dev', '/dev/gallery', '/dev/screens/account_show']);

it('CAP-004 serves the shell without the dev flag outside local and testing', function (string $path) {
    app()->detectEnvironment(fn () => 'production');

    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="app"></div>', escape: false)
        ->assertDontSee('bb-dev-routes');
})->with(['/', '/devices']);

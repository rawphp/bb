<?php

// Feature, not unit: the app learns each write's affects from the catalog route
// it can reach with its session, so the seam is the package's GET /capabilities.

use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

it('OFF-008 lists each write\'s affects as catalog tags the app can read with its session', function () {
    (new TallyAddCapability)->definition()->register(app(CapabilityRegistry::class));

    $capabilities = collect($this->actingAs(User::factory()->create())
        ->getJson('/capabilities')
        ->assertOk()
        ->json('data.capabilities'));

    expect($capabilities->firstWhere('name', 'tally_add')['tags'])->toContain('affects:tally_show')
        ->and($capabilities->firstWhere('name', 'undo_apply')['tags'])->toContain('affects:*');
});

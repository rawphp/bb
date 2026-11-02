<?php

use App\Actions\Activity\ListActivity;
use App\Models\AuditRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is which audit_records rows it lists, so it runs on the database.
uses(RefreshDatabase::class);

it('CAP-022 lists only the user\'s own capability invokes with a channel, newest first', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $at = CarbonImmutable::parse('2026-11-02T08:00:00Z');
    $older = AuditRecord::factory()->for($user)->create(['created_at' => $at]);
    $newer = AuditRecord::factory()->for($user)->create(['event' => 'capability.failed', 'created_at' => $at->addMinute()]);
    AuditRecord::factory()->for($user)->create(['event' => 'approval.decided', 'capability' => null, 'created_at' => $at]);
    AuditRecord::factory()->for($user)->create(['channel' => null, 'created_at' => $at]);
    AuditRecord::factory()->for($other)->create(['created_at' => $at]);

    $entries = (new ListActivity)($user, $at->addHour());

    expect(array_map(fn ($entry): string => $entry->id, $entries))->toBe([$newer->id, $older->id]);
});

it('CAP-022 lists at most the last 100', function () {
    $user = User::factory()->create();
    AuditRecord::factory()->for($user)->count(ListActivity::LIMIT + 1)->create();

    expect((new ListActivity)($user, CarbonImmutable::now()))->toHaveCount(100);
});

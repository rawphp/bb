<?php

use App\Models\AuditRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rawphp\Capabilities\Persistence\MigrationCatalog;

it('CAP-022 moves the package outbox\'s audit rows into audit_records and drops the outbox', function () {
    $migration = require database_path('migrations/2026_11_02_120100_move_capabilities_audit_outbox_into_audit_records.php');
    // DDL commits on MySQL, so this test rebuilds the database when it is done.
    try {
        $migration->down();
        $user = User::factory()->create();
        $row = fn (string $id, string $actor, string $caller, string $at): array => [
            'id' => $id, 'event' => 'capability.invoked', 'capability_name' => 'tally_add', 'status' => 'pending',
            'payload_json' => json_encode([
                'event' => 'capability.invoked', 'capability_name' => 'tally_add', 'caller' => $caller,
                'actor_type' => 'user', 'actor_id' => $actor, 'redacted_input' => ['amount' => 3],
                'result' => ['ok' => true], 'recorded_at' => $at,
            ]),
            'created_at' => $at, 'updated_at' => $at,
        ];
        DB::table(MigrationCatalog::TABLE_AUDIT_OUTBOX)->insert([
            $row('a', (string) $user->id, 'mcp', '2026-10-01T08:00:00+00:00'),
            $row('b', (string) ($user->id + 1000), 'http', '2026-10-02T08:00:00+00:00'),
        ]);

        $migration->up();

        expect(Schema::hasTable(MigrationCatalog::TABLE_AUDIT_OUTBOX))->toBeFalse();
        $records = AuditRecord::query()->orderBy('created_at')->get();
        expect($records->map(fn (AuditRecord $r): array => [$r->user_id, $r->channel, $r->created_at->toIso8601String(), $r->input])->all())
            ->toBe([
                [$user->id, 'mcp', '2026-10-01T08:00:00+00:00', ['amount' => 3]],
                [null, 'ui', '2026-10-02T08:00:00+00:00', ['amount' => 3]],
            ]);
    } finally {
        $this->warpRecycleDatabase();
    }
});

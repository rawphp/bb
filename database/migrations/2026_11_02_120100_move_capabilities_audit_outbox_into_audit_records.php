<?php

use App\Capabilities\Support\AuditRecordWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rawphp\Capabilities\Persistence\MigrationCatalog;

/**
 * audit_records is the one audit record (CAP-022): AuditRecordWriter replaces the
 * package's writer, so nothing writes the outbox table again. Its rows move to
 * audit_records, dated by the time each was recorded, and the table goes. An
 * actor that is no longer a user is kept as a system actor, as account deletion
 * would have left it (ACC-005).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = MigrationCatalog::TABLE_AUDIT_OUTBOX;
        if (! Schema::hasTable($table)) {
            return;
        }

        $writer = app(AuditRecordWriter::class);
        DB::table($table)->orderBy('created_at')->orderBy('id')->each(function (object $row) use ($writer): void {
            $entry = json_decode((string) $row->payload_json, true);
            if (! is_array($entry)) {
                return;
            }
            $entry['recorded_at'] ??= $row->created_at;
            $id = $entry['actor_id'] ?? null;
            if (! is_string($id) || ! ctype_digit($id) || ! DB::table('users')->where('id', (int) $id)->exists()) {
                $entry['actor_type'] = null;
            }
            $writer->write($entry);
        });

        Schema::drop($table);
    }

    /** The table comes back empty: its rows stay in audit_records. */
    public function down(): void
    {
        (require __DIR__.'/2026_07_27_000003_create_capabilities_audit_outbox_table.php')->up();
    }
};

<?php

namespace App\Models;

use Database\Factories\AuditOutboxRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Rawphp\Capabilities\Persistence\MigrationCatalog;

/**
 * A row the package's audit writer left in capabilities_audit_outbox before
 * audit_records replaced it (CAP-022). Nothing writes the table now, but its
 * rows still hold users' redacted inputs, so they keep the same rules as
 * AuditRecord: pruned after 90 days, and deleted with the account (ACC-005).
 * The table is the package's, so it is never altered or dropped here.
 */
#[Fillable(['id', 'event', 'capability_name', 'payload_json', 'status', 'created_at', 'updated_at'])]
class AuditOutboxRow extends Model
{
    /** @use HasFactory<AuditOutboxRowFactory> */
    use HasFactory, MassPrunable;

    public $incrementing = false;

    protected $keyType = 'string';

    public function getTable(): string
    {
        return MigrationCatalog::TABLE_AUDIT_OUTBOX;
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(AuditRecord::KEEP_DAYS));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload_json' => 'array'];
    }

    /**
     * The user's rows. The actor is in the payload, with no foreign key to cascade.
     *
     * @return Builder<static>
     */
    public static function ownedBy(User $user): Builder
    {
        return static::query()
            ->where('payload_json->actor_type', 'user')
            ->where('payload_json->actor_id', (string) $user->id);
    }
}

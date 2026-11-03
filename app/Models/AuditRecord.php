<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\AuditRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One audited invoke (CAP-022), written by AuditRecordWriter. Activity lists
 * the user's last 100; a Coach turn's writes carry its turn_ulid and are its
 * change cards (AGT-013). Rows older than 90 days are pruned daily, as the spec
 * requires (build plan, AUDIT), and deleting the user deletes them (ACC-005).
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $event
 * @property string|null $capability
 * @property string|null $channel
 * @property array<array-key, mixed>|null $input
 * @property array<string, mixed>|null $result
 * @property string|null $turn_ulid
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'event', 'capability', 'channel', 'input', 'result', 'turn_ulid', 'created_at'])]
class AuditRecord extends Model
{
    /** @use HasFactory<AuditRecordFactory> */
    use BelongsToUser, HasFactory, HasUlids, MassPrunable;

    public const KEEP_DAYS = 90;

    public const UPDATED_AT = null;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
        ];
    }
}

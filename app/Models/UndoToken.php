<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\UndoTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reversible write's inverse, kept under its undo token until it is applied
 * or 24 hours pass (CAP-013). Written and read only by UndoLog; expired ones are
 * pruned by the scheduled model:prune.
 *
 * @property string $id
 * @property int $user_id
 * @property list<array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>, after: ?array<string, mixed>}> $changes
 * @property list<string> $invalidates
 * @property Carbon $expires_at
 */
#[Fillable(['changes', 'invalidates', 'expires_at'])]
class UndoToken extends Model
{
    /** @use HasFactory<UndoTokenFactory> */
    use BelongsToUser, HasFactory, HasUlids, MassPrunable;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'invalidates' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}

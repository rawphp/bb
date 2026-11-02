<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\UndoTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reversible write's inverse, kept under its undo token until it is applied
 * or 24 hours pass (CAP-013). Written and read only by UndoLog.
 *
 * @property string $id
 * @property int $user_id
 * @property list<array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>, after: ?array<string, mixed>}> $changes
 * @property list<string> $invalidates
 * @property Carbon $expires_at
 */
class UndoToken extends Model
{
    /** @use HasFactory<UndoTokenFactory> */
    use BelongsToUser, HasFactory, HasUlids;

    protected $guarded = [];

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

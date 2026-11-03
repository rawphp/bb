<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\ClearedCoachTurnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A turn of a conversation the user cleared, kept so the AGT-005 caps still count
 * it (AgentStatus). Written by ClearCoachConversation; pruned daily once its month,
 * and the two days before it, are over.
 *
 * @property int $id
 * @property int $user_id
 * @property int $tokens
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'tokens', 'created_at'])]
class ClearedCoachTurn extends Model
{
    /** @use HasFactory<ClearedCoachTurnFactory> */
    use BelongsToUser, HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->startOfMonth()->subDays(2));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tokens' => 'integer'];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\ReminderMorningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A morning a pass judged a user's 09:00 reminders (NOTIF-004, NOTIF-005), sent or not, so the minutes after 09:00 do
 * not queue that user again. Only today's row matters, so the daily model:prune drops rows older than two days.
 *
 * @property int $user_id
 * @property Carbon $date
 * @property Carbon $judged_at
 */
#[Fillable(['user_id', 'date', 'judged_at'])]
class ReminderMorning extends Model
{
    /** @use HasFactory<ReminderMorningFactory> */
    use BelongsToUser, HasFactory, MassPrunable;

    public const KEEP_DAYS = 2;

    public $timestamps = false;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('judged_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date', 'judged_at' => 'datetime'];
    }
}

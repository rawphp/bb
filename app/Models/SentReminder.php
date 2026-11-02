<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\SentReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reminder the server sent, so the next pass does not send it again (NOTIF-001 to NOTIF-005). A reminder is only
 * sent within minutes of falling due, so the daily model:prune drops rows older than two days.
 *
 * @property int $user_id
 * @property string $reminder_id
 * @property Carbon $due_at
 * @property Carbon $sent_at
 */
#[Fillable(['user_id', 'reminder_id', 'due_at', 'sent_at'])]
class SentReminder extends Model
{
    /** @use HasFactory<SentReminderFactory> */
    use BelongsToUser, HasFactory, MassPrunable;

    public const KEEP_DAYS = 2;

    public $timestamps = false;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('sent_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}

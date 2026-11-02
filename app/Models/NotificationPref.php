<?php

namespace App\Models;

use App\Domain\Notifications\ReminderSwitches;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\NotificationPrefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One reminder type the user switched on or off (NOTIF-007). A type with no row keeps its default.
 *
 * @property int $user_id
 * @property string $kind
 * @property bool $on
 */
#[Fillable(['user_id', 'kind', 'on'])]
class NotificationPref extends Model
{
    /** @use HasFactory<NotificationPrefFactory> */
    use BelongsToUser, HasFactory;

    /** The user's switches, for the sender and for settings_show. */
    public static function switches(User|int $user): ReminderSwitches
    {
        return new ReminderSwitches(static::query()
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->pluck('on', 'kind')
            ->map(fn (mixed $on): bool => (bool) $on)
            ->all());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['on' => 'boolean'];
    }
}

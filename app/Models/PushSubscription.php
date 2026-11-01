<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NotificationChannels\WebPush\PushSubscription as WebPushSubscription;

/**
 * One browser's Web Push subscription. The package's model is polymorphic;
 * ours belongs to a user so the row goes when the user does (ACC-005).
 *
 * @property int $user_id
 */
class PushSubscription extends WebPushSubscription
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

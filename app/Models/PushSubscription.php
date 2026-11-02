<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use NotificationChannels\WebPush\PushSubscription as WebPushSubscription;

/**
 * One browser's Web Push subscription. The package's model is polymorphic;
 * ours belongs to a user so the row goes when the user does (ACC-005).
 *
 * @property int $user_id
 */
class PushSubscription extends WebPushSubscription
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
    ];

    /** Whether the endpoint is an https URL on one of `webpush.push_services`. */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach (config()->array('webpush.push_services') as $service) {
            if ($host === $service || str_ends_with($host, '.'.$service)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Http\Request;
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

    /**
     * Names this browser's endpoint. usePush() sets it when it saves the
     * subscription, so sign-in and sign-out can find this browser's row.
     * Plain, not encrypted: the app writes it.
     */
    public const BROWSER_COOKIE = 'bb_push_endpoint';

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

    /** The endpoint the browser behind this request named, if it has one. */
    public static function browserEndpoint(Request $request): ?string
    {
        $endpoint = $request->cookie(self::BROWSER_COOKIE);

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }
}

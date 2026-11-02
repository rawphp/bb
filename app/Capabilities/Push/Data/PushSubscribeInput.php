<?php

namespace App\Capabilities\Push\Data;

use App\Models\PushSubscription;
use Closure;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The browser's PushSubscription: its endpoint and its `keys.p256dh` and `keys.auth`. */
final class PushSubscribeInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The push service URL for this browser.', format: 'uri', maxLength: 1024)]
        public readonly string $endpoint,
        #[Field(description: 'The subscription\'s keys.p256dh.', maxLength: 255)]
        public readonly string $public_key,
        #[Field(description: 'The subscription\'s keys.auth.', maxLength: 255)]
        public readonly string $auth_token,
    ) {}

    public static function rules(): array
    {
        return ['endpoint' => [function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! PushSubscription::allowedEndpoint($value)) {
                $fail('The endpoint must be an https URL on a known push service.');
            }
        }]];
    }
}

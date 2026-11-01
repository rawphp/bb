<?php

namespace App\Capabilities\Push\Data;

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
        return ['endpoint' => ['starts_with:https://']];
    }
}

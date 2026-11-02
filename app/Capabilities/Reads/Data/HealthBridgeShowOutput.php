<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Settings\BridgePost;
use App\Capabilities\Reads\Data\Settings\BridgeToken;
use App\Capabilities\Reads\Data\Settings\TextLine;
use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** health_bridge_show: the Health bridge page (INT-001, INT-004). It connects with token_create (scope health_bridge) and disconnects with token_revoke. */
final class HealthBridgeShowOutput extends CapabilityData
{
    /**
     * @param  list<TextLine>  $steps
     */
    public function __construct(
        public readonly Status $status,
        #[Field(description: 'what the token can and cannot do')]
        public readonly string $scope_text,
        #[Field(description: 'null until the bridge is set up')]
        public readonly ?BridgeToken $token,
        #[Field(description: 'null until the bridge has posted')]
        public readonly ?BridgePost $last_post,
        public readonly string $shortcut_url,
        #[Field(description: 'the Shortcut\'s steps, to build it by hand', items: TextLine::class)]
        public readonly array $steps,
    ) {}
}

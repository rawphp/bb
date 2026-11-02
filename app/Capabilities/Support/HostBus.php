<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Schema\CatalogPresenter;
use Rawphp\Capabilities\Support\CapabilityResult;

/**
 * The bus the HTTP surface and the in-app agent invoke through. It runs the
 * registry and words its failures with {@see ErrorWording}.
 *
 * Over http the caller is the one derived from the credential: a claimed
 * X-Capabilities-Caller is ignored, so Activity's channel stays the server's (CAP-022).
 */
final class HostBus implements CapabilityBus
{
    public function __construct(private readonly CapabilityRegistry $registry) {}

    public function invoke(string $nameOrAlias, array $input = [], array $options = []): CapabilityResult
    {
        if (isset($options['derived_caller'])) {
            $options['caller'] = $options['derived_caller'];
        }

        return ErrorWording::apply($this->registry->invoke($nameOrAlias, $input, $options));
    }

    public function catalog(): CatalogPresenter
    {
        return $this->registry->catalog();
    }
}

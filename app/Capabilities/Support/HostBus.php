<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Schema\CatalogPresenter;
use Rawphp\Capabilities\Support\CapabilityResult;

/**
 * The bus the HTTP surface and the in-app agent invoke through. It runs the
 * registry and words its failures with {@see ErrorWording}.
 */
final class HostBus implements CapabilityBus
{
    public function __construct(private readonly CapabilityRegistry $registry) {}

    public function invoke(string $nameOrAlias, array $input = [], array $options = []): CapabilityResult
    {
        return ErrorWording::apply($this->registry->invoke($nameOrAlias, $input, $options));
    }

    public function catalog(): CatalogPresenter
    {
        return $this->registry->catalog();
    }
}

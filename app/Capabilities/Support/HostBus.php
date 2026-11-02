<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Schema\CatalogPresenter;
use Rawphp\Capabilities\Support\CapabilityResult;

/**
 * The bus the HTTP surface and the in-app agent invoke through. It runs the
 * registry and words its failures with {@see ErrorWording}. A refusal for
 * another user's row ID is answered 404, not 403 (CAP-020 step 2, {@see Ownership}).
 */
final class HostBus implements CapabilityBus
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Ownership $ownership,
    ) {}

    public function invoke(string $nameOrAlias, array $input = [], array $options = []): CapabilityResult
    {
        [$result, $denied] = $this->ownership->watch(fn (): CapabilityResult => $this->registry->invoke($nameOrAlias, $input, $options));

        if ($denied && $result->errorCode() === 'forbidden') {
            $result = CapabilityResult::failure(code: 'not_found', message: 'Not found.', meta: $result->meta);
        }

        return ErrorWording::apply($result);
    }

    public function catalog(): CatalogPresenter
    {
        return $this->registry->catalog();
    }
}

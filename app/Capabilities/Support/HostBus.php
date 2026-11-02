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
 *
 * Over http the caller is the one derived from the credential: a claimed
 * X-Capabilities-Caller is ignored, so Activity's channel stays the server's (CAP-022).
 */
final class HostBus implements CapabilityBus
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Ownership $ownership,
    ) {}

    public function invoke(string $nameOrAlias, array $input = [], array $options = []): CapabilityResult
    {
        // Over http the caller is the one derived from the credential (CAP-022).
        if (isset($options['derived_caller'])) {
            $options['caller'] = $options['derived_caller'];
        }

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

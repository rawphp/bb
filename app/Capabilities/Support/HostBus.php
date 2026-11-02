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
 * A rate limit's refusal before the run gives the write's idempotency key back
 * ({@see IdempotencyKeys::releaseRateLimited()}).
 */
final class HostBus implements CapabilityBus
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Ownership $ownership,
        private readonly ?IdempotencyKeys $keys = null,
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

        // A write the rate limit refused before it ran must not spend its key (OFF-011).
        $key = $options['idempotency_key'] ?? null;
        $actor = $options['actor'] ?? null;
        if ($result->errorCode() === 'rate_limited' && is_string($key) && $key !== '' && is_object($actor)) {
            $this->keys?->releaseRateLimited($actor, $this->registry->get($nameOrAlias)->name, $key);
        }

        return ErrorWording::apply($result);
    }

    public function catalog(): CatalogPresenter
    {
        return $this->registry->catalog();
    }
}

<?php

namespace App\Capabilities\Support;

use App\Models\User;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * CAP-020 step 2: every row ID in the input belongs to the actor.
 *
 * The package's Authorizer can only answer 403, and CAP-020 answers another
 * user's ID with 404. So {@see HostBus} runs each invoke inside {@see watch()},
 * which reports whether a check failed during it, and turns that `forbidden`
 * into `not_found`. A check outside a watch is remembered nowhere. Invokes that
 * bypass the bus (the job and MCP surfaces call the registry) are still refused,
 * with 403, until the package lets an authorizer deny with a code.
 */
final class Ownership
{
    /** @var array<string, OwnsInput> capability name => capability */
    private array $capabilities = [];

    /** Whether a check failed in the innermost open watch; null with none open. */
    private ?bool $denied = null;

    public function declare(string $capability, OwnsInput $owner): void
    {
        $this->capabilities[$capability] = $owner;
    }

    public function allows(string $capability, mixed $input, User $user): bool
    {
        $owner = $this->capabilities[$capability] ?? null;
        if ($owner === null) {
            return true;
        }

        $values = match (true) {
            $input instanceof CapabilityData => $input->toArray(),
            is_array($input) => $input,
            default => [],
        };

        foreach ($owner->owned($user) as $field => $query) {
            $id = $values[$field] ?? null;
            if ($id === null) {
                continue;
            }

            if ((! is_string($id) && ! is_int($id)) || ! $query->whereKey($id)->exists()) {
                if ($this->denied !== null) {
                    $this->denied = true;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Run an invoke and report whether an ownership check failed during it. A watch
     * opened inside it (a nested invoke) reports its own checks, and the outer
     * watch's state is put back when it ends.
     *
     * @template T
     *
     * @param  callable(): T  $invoke
     * @return array{0: T, 1: bool} the invoke's result, and whether a check failed
     */
    public function watch(callable $invoke): array
    {
        $outer = $this->denied;
        $this->denied = false;

        try {
            $result = $invoke();

            return [$result, $this->denied];
        } finally {
            $this->denied = $outer;
        }
    }
}

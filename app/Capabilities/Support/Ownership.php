<?php

namespace App\Capabilities\Support;

use App\Models\User;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * CAP-020 step 2: every row ID in the input belongs to the actor.
 *
 * The package's Authorizer can only answer 403, and CAP-020 answers another
 * user's ID with 404. So a failed check is remembered here, and {@see HostBus}
 * turns that invoke's `forbidden` into `not_found`. Invokes that bypass the bus
 * (the job and MCP surfaces call the registry) are still refused, with 403.
 */
final class Ownership
{
    /** @var array<string, OwnsInput> capability name => capability */
    private array $capabilities = [];

    private bool $denied = false;

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
                $this->denied = true;

                return false;
            }
        }

        return true;
    }

    /** Whether a check failed since the last call, and forget it. */
    public function pullDenied(): bool
    {
        $denied = $this->denied;
        $this->denied = false;

        return $denied;
    }
}

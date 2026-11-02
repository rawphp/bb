<?php

namespace App\Capabilities\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            $ids = self::idsAt($values, $field);
            if ($ids === []) {
                continue;
            }

            if (! self::ownsAll($query, $ids)) {
                if ($this->denied !== null) {
                    $this->denied = true;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * The IDs at a field: `id`, a list (`meal_ids`), or a dotted path into nested input,
     * with `*` for each item of a list (`entries.*.recipe_id`). Missing and null are no ID.
     *
     * @param  array<string, mixed>  $values
     * @return array<mixed>
     */
    private static function idsAt(array $values, string $field): array
    {
        $found = data_get($values, $field);
        if ($found === null) {
            return [];
        }

        if (! str_contains($field, '*')) {
            return is_array($found) ? $found : [$found];
        }

        // Each item holds one ID, or a list of them when the field names one (`*_ids`).
        $lists = str_ends_with($field, '_ids');
        $ids = [];
        foreach ((array) $found as $item) {
            if ($item !== null) {
                array_push($ids, ...($lists && is_array($item) ? array_values($item) : [$item]));
            }
        }

        return $ids;
    }

    /**
     * Whether the user owns every ID: a field holds one ID, or a list of them (`*_ids`).
     *
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @param  array<mixed>  $ids
     */
    private static function ownsAll(Builder|Relation $query, array $ids): bool
    {
        foreach ($ids as $id) {
            if (! is_string($id) && ! is_int($id)) {
                return false;
            }
        }

        $distinct = array_values(array_unique(array_map('strval', $ids)));

        return $distinct === [] || (clone $query)->whereKey($distinct)->count() === count($distinct);
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

<?php

namespace App\Capabilities\Support;

use InvalidArgumentException;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

/**
 * The start of every capability definition. A read is read-only; a write
 * returns a {@see WriteResult} and names the reads it can change (CAP-011).
 *
 * The package catalog has no `affects` field, so each entry is the tag
 * `affects:<read name>`. Clients read it from the catalog's `tags`.
 * A read may register after the write that names it, so {@see unknownAffects()}
 * checks the names against the whole registry once it is built (a freeze test).
 *
 * A write marked reversible carries the tag `reversible` and returns an undo
 * token from {@see UndoLog::record()} (CAP-013).
 */
final class HostDefinition
{
    public const SURFACES = ['http', 'job', 'mcp', 'cli', 'agent'];

    /**
     * An affects entry for a write that can change any read, such as undo_apply.
     * Its result's `invalidates` names the reads it did change.
     */
    public const ANY_READ = '*';

    private const AFFECTS_TAG = 'affects:';

    private const REVERSIBLE_TAG = 'reversible';

    public static function read(string $name): CapabilityDefinitionBuilder
    {
        return Capability::define($name)
            ->surfaces(self::SURFACES)
            ->readOnly();
    }

    /**
     * @param  list<string>  $affects  the read capabilities this write can change
     */
    public static function write(string $name, array $affects, bool $reversible = false): CapabilityDefinitionBuilder
    {
        if ($affects === []) {
            throw new InvalidArgumentException("{$name} must list the reads it affects (CAP-011).");
        }

        foreach ($affects as $read) {
            if ($read !== self::ANY_READ && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $read) !== 1) {
                throw new InvalidArgumentException("{$name} affects \"{$read}\", which is not a capability name.");
            }
        }

        return Capability::define($name)
            ->surfaces(self::SURFACES)
            ->output(WriteResult::class)
            ->tags([
                ...array_map(fn (string $read): string => self::AFFECTS_TAG.$read, $affects),
                ...($reversible ? [self::REVERSIBLE_TAG] : []),
            ]);
    }

    public static function isReversible(CapabilityDefinition $definition): bool
    {
        return in_array(self::REVERSIBLE_TAG, $definition->tags, true);
    }

    /**
     * @return list<string>
     */
    public static function affects(CapabilityDefinition $definition): array
    {
        $affects = [];
        foreach ($definition->tags as $tag) {
            if (str_starts_with($tag, self::AFFECTS_TAG)) {
                $affects[] = substr($tag, strlen(self::AFFECTS_TAG));
            }
        }

        return $affects;
    }

    /**
     * Every affects entry that does not name a registered read, as `<write> affects <name>`.
     *
     * @return list<string>
     */
    public static function unknownAffects(CapabilityRegistry $registry): array
    {
        $definitions = $registry->all();
        $unknown = [];
        foreach ($definitions as $definition) {
            foreach (self::affects($definition) as $read) {
                if ($read !== self::ANY_READ && ! ($definitions[$read]->readOnly ?? false)) {
                    $unknown[] = "{$definition->name} affects {$read}";
                }
            }
        }

        return $unknown;
    }
}

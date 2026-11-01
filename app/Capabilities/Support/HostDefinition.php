<?php

namespace App\Capabilities\Support;

use InvalidArgumentException;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

/**
 * The start of every capability definition. A read is read-only; a write
 * returns a {@see WriteResult} and names the reads it can change (CAP-011).
 *
 * The package catalog has no `affects` field, so each entry is the tag
 * `affects:<read name>`. Clients read it from the catalog's `tags`.
 */
final class HostDefinition
{
    public const SURFACES = ['http', 'job', 'mcp', 'cli'];

    private const AFFECTS_TAG = 'affects:';

    public static function read(string $name): CapabilityDefinitionBuilder
    {
        return Capability::define($name)
            ->surfaces(self::SURFACES)
            ->readOnly();
    }

    /**
     * @param  list<string>  $affects  the read capabilities this write can change
     */
    public static function write(string $name, array $affects): CapabilityDefinitionBuilder
    {
        if ($affects === []) {
            throw new InvalidArgumentException("{$name} must list the reads it affects (CAP-011).");
        }

        foreach ($affects as $read) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $read) !== 1) {
                throw new InvalidArgumentException("{$name} affects \"{$read}\", which is not a capability name.");
            }
        }

        return Capability::define($name)
            ->surfaces(self::SURFACES)
            ->output(WriteResult::class)
            ->tags(array_map(fn (string $read): string => self::AFFECTS_TAG.$read, $affects));
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
}

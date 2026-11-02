<?php

namespace Tests\Support;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Finds the DTOs behind a contract: the capability names an area's owners file
 * lists, and each name's `<StudlyName>Input` and `...Output` class under
 * app/Capabilities/<Module>/Data/.
 */
final class ContractDtos
{
    /** @return array<string, int> capability name → owning issue, across every area */
    public static function owners(string $root): array
    {
        $owners = [];
        foreach (glob("{$root}/tests/fixtures/schemas/*.owners.json") ?: [] as $path) {
            $owners += json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }
        ksort($owners);

        return $owners;
    }

    /** @return list<string> */
    public static function ownedNames(string $root): array
    {
        return array_keys(self::owners($root));
    }

    /** @return class-string<CapabilityData>|null */
    public static function find(string $root, string $name, string $side): ?string
    {
        $class = str_replace('_', '', ucwords($name, '_')).ucfirst($side);
        $paths = glob("{$root}/app/Capabilities/*/Data/{$class}.php") ?: [];

        if (count($paths) > 1) {
            throw new \LogicException("More than one {$class}: ".implode(', ', $paths));
        }
        if ($paths === []) {
            return null;
        }

        $module = basename(dirname($paths[0], 2));
        $fqcn = "App\\Capabilities\\{$module}\\Data\\{$class}";

        if (! is_a($fqcn, CapabilityData::class, true)) {
            throw new \LogicException("{$fqcn} is not a CapabilityData");
        }

        return $fqcn;
    }

    /** @param  class-string<CapabilityData>  $class */
    public static function snapshot(string $class): string
    {
        return json_encode($class::jsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}

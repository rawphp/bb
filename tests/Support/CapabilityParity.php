<?php

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The CAP-030 parity check: every capability the Vue app calls, and every one
 * registered, is either an agent tool or on the CAP-031 exclusion list.
 */
final class CapabilityParity
{
    /** `read('name'` or `write<T>("name"`, not a method such as `cache.read(` or `fetchRead(`. */
    private const CALL = '/(?<![\w.$])(?:read|write)\s*(?:<[^()]*?>)?\s*\(\s*([\'"`])([a-z][a-z0-9_]*)\1/';

    /**
     * @return list<string> the capability names in the source, in order, each once
     */
    public static function calledIn(string $source): array
    {
        preg_match_all(self::CALL, $source, $matches);

        return array_values(array_unique($matches[2]));
    }

    /**
     * Every capability name the app calls, from the .ts and .vue files under
     * $root. Test files call made-up names, so they are left out.
     *
     * @return array<string, list<string>> name => the files that call it, relative to $root
     */
    public static function calledInApp(string $root): array
    {
        $calls = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if (preg_match('/(?<!\.test|\.spec)\.(ts|vue)$/', $path) !== 1) {
                continue;
            }

            $relative = ltrim(substr($path, strlen($root)), '/');
            foreach (self::calledIn((string) file_get_contents($path)) as $name) {
                $calls[$name][] = $relative;
            }
        }

        ksort($calls);

        return array_map(function (array $files): array {
            sort($files);

            return $files;
        }, $calls);
    }

    /**
     * @return list<string> the capability names with a schema snapshot in $dir, sorted
     */
    public static function snapshotNames(string $dir): array
    {
        $names = [];
        foreach (glob($dir.'/*.{input,output}.json', GLOB_BRACE) ?: [] as $path) {
            $names[] = (string) preg_replace('/\.(input|output)\.json$/', '', basename($path));
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * The API issue that will register each capability, from the <area>.owners.json files in $dir.
     *
     * @return array<string, int> name => issue number
     */
    public static function owners(string $dir): array
    {
        $owners = [];
        foreach (glob($dir.'/*.owners.json') ?: [] as $path) {
            /** @var array<string, int> $area */
            $area = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $owners = [...$owners, ...$area];
        }

        return $owners;
    }

    /**
     * A name the app calls passes when it is registered, or when it is a contract
     * waiting for its API issue: a schema snapshot, and an owners entry naming
     * the issue that registers it. That issue's own gate then checks the name
     * is registered, at which point the agent check below applies to it.
     *
     * @param  array<string, list<string>>  $ui  names the app calls => the files that call them
     * @param  list<string>  $registered  every registered capability
     * @param  list<string>  $tools  the agent's tool names
     * @param  list<string>  $snapshots  names with a schema snapshot in tests/fixtures/schemas/
     * @param  array<string, int>  $owners  names => the API issue that registers them
     * @param  callable(string): ?string  $excluded  the CAP-031 reason for a name, or null
     * @return list<string> one line per problem, empty when the app and the agent agree
     */
    public static function problems(array $ui, array $registered, array $tools, array $snapshots, array $owners, callable $excluded): array
    {
        $problems = [];

        foreach ($ui as $name => $files) {
            if (in_array($name, $registered, true)) {
                continue;
            }
            $callers = implode(', ', $files);
            if (! in_array($name, $snapshots, true)) {
                $problems[] = "{$callers} calls {$name}, which is not registered and has no schema snapshot in tests/fixtures/schemas/";
            } elseif (! isset($owners[$name])) {
                $problems[] = "{$callers} calls {$name}, which is not registered and no owners file names the issue that registers it";
            }
        }

        $sorted = $registered;
        sort($sorted);
        foreach ($sorted as $name) {
            $tool = in_array($name, $tools, true);
            $reason = $excluded($name);

            if (! $tool && $reason === null) {
                $problems[] = "{$name} is registered but not an agent tool, and not on the CAP-031 exclusion list";
            } elseif ($tool && $reason !== null) {
                $problems[] = "{$name} is on the CAP-031 exclusion list but is still an agent tool";
            }
        }

        return $problems;
    }
}

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
     * @param  array<string, list<string>>  $ui  names the app calls => the files that call them
     * @param  list<string>  $registered  every registered capability
     * @param  list<string>  $tools  the agent's tool names
     * @param  list<string>  $snapshots  names with a schema snapshot in tests/fixtures/schemas/
     * @param  callable(string): ?string  $excluded  the CAP-031 reason for a name, or null
     * @return list<string> one line per problem, empty when the app and the agent agree
     */
    public static function problems(array $ui, array $registered, array $tools, array $snapshots, callable $excluded): array
    {
        $problems = [];

        foreach ($ui as $name => $files) {
            if (! in_array($name, $registered, true) && ! in_array($name, $snapshots, true)) {
                $problems[] = implode(', ', $files)." calls {$name}, which is not registered and has no schema snapshot in tests/fixtures/schemas/";
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

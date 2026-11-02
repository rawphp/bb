<?php

namespace App\Capabilities\Support;

use App\Models\User;
use InvalidArgumentException;
use LogicException;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * The start of every capability definition. A read is read-only; a write
 * returns a {@see WriteResult} and names the reads it can change (CAP-011).
 *
 * The package catalog has no `affects` field, so each entry is the tag
 * `affects:<read name>`. Clients read it from the catalog's `tags`.
 * A read may register after the write that names it, so {@see unknownAffects()}
 * checks the names against the whole registry once it is built (a freeze test).
 *
 * A write marked reversible carries the tag `reversible`, and {@see register()}
 * runs it inside {@see UndoLog::record()}, which returns its undo token (CAP-013).
 * The tag and the recording are set in that one place, so they cannot drift apart.
 */
final class HostDefinition
{
    public const SURFACES = ['http', 'job', 'mcp', 'cli', 'agent'];

    /**
     * An affects entry for a write that can change any read, such as undo_apply.
     * Its result's `invalidates` names the reads it did change.
     */
    public const ANY_READ = '*';

    /** The writes that may affect any read; the freeze test names any other that claims it. */
    private const ANY_READ_WRITES = ['undo_apply'];

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

    /**
     * Register a host capability's definition. A reversible write's run is wrapped in
     * {@see UndoLog::record()} here, so every write tagged reversible returns an undo token.
     */
    public static function register(CapabilityDefinitionBuilder $builder, CapabilityRegistry $registry): CapabilityDefinition
    {
        $definition = $builder->toDefinition();

        if (self::isReversible($definition)) {
            $run = $definition->run;
            if (! is_callable($run)) {
                throw new LogicException("{$definition->name} is reversible but has no run closure to record.");
            }

            // The builder's own run(), so the package builds the definition as it always does.
            $name = $definition->name;
            $definition = $builder->run(function (mixed $input, CapabilityContext $context) use ($name, $run): WriteResult {
                $user = $context->user();
                if (! $user instanceof User) {
                    throw new LogicException("{$name} is reversible, so it needs a user to hold its undo token.");
                }

                return app(UndoLog::class)->record($user, fn (): WriteResult => $run($input, $context));
            })->toDefinition();
        }

        $registry->register($definition);

        return $definition;
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
     * Only the writes in ANY_READ_WRITES may affect any read.
     *
     * @return list<string>
     */
    public static function unknownAffects(CapabilityRegistry $registry): array
    {
        $definitions = $registry->all();
        $unknown = [];
        foreach ($definitions as $definition) {
            foreach (self::affects($definition) as $read) {
                $anyRead = $read === self::ANY_READ && in_array($definition->name, self::ANY_READ_WRITES, true);
                if (! $anyRead && ! ($definitions[$read]->readOnly ?? false)) {
                    $unknown[] = "{$definition->name} affects {$read}";
                }
            }
        }

        return $unknown;
    }
}

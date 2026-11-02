<?php

namespace App\Capabilities\Sync;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\PrefetchesKeys;
use App\Capabilities\Sync\Data\SyncManifestInput;
use App\Capabilities\Sync\Data\SyncManifestOutput;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * The read keys the app keeps warm for offline use (OFF-004): the keys of every
 * capability that implements {@see PrefetchesKeys}, in load order, each once.
 */
final class SyncManifestCapability implements HostCapability
{
    /** The container that built this class, which is the one registerFrom tags the prefetchers on. */
    public function __construct(private readonly Container $container) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('sync_manifest')
            ->description('List the read keys the app should keep cached for offline use, as named by the reads that prefetch. It changes nothing.')
            ->cli('sync', 'manifest')
            ->input(SyncManifestInput::class)
            ->output(SyncManifestOutput::class)
            ->run(fn (SyncManifestInput $input, CapabilityContext $context): SyncManifestOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): SyncManifestOutput
    {
        /** @var User $user */
        $user = $context->user();

        $keys = [];
        $asked = [];
        /** @var PrefetchesKeys $capability */
        foreach ($this->container->tagged(PrefetchesKeys::class) as $capability) {
            // A class tagged twice (registerFrom run again on this container) is asked once.
            if (isset($asked[$capability::class])) {
                continue;
            }
            $asked[$capability::class] = true;
            array_push($keys, ...$capability->prefetchKeys($user));
        }

        return new SyncManifestOutput(keys: array_values(array_unique($keys)));
    }
}

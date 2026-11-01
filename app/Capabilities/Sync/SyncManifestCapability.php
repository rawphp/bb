<?php

namespace App\Capabilities\Sync;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\PrefetchesKeys;
use App\Capabilities\Sync\Data\SyncManifestInput;
use App\Capabilities\Sync\Data\SyncManifestOutput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * The read keys the app keeps warm for offline use (OFF-004): the keys of every
 * capability that implements {@see PrefetchesKeys}, in load order, each once.
 */
final class SyncManifestCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('sync_manifest')
            ->description('List the read keys the app should keep cached for offline use: the coming days, their workouts and meals, the current mesocycle and the weigh-in screen. It changes nothing.')
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
        // Resolved per call from the current container, which may not be the one this class was built in.
        /** @var PrefetchesKeys $capability */
        foreach (app()->tagged(PrefetchesKeys::class) as $capability) {
            array_push($keys, ...$capability->prefetchKeys($user));
        }

        return new SyncManifestOutput(keys: array_values(array_unique($keys)));
    }
}

<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Rawphp\CapabilitiesAi\Contracts\ProgressStore;

/**
 * A turn's progress events on Laravel's cache, for the e2e server: each request there is its own PHP process with no
 * Redis, so the array store would lose a turn's events before the page polls them. Same events and `index` as the
 * package's ArrayProgressStore.
 */
final class CacheProgressStore implements ProgressStore
{
    public function append(string $turnUlid, array $event): void
    {
        if ($event['kind'] === '') {
            throw new InvalidArgumentException('Progress event requires kind');
        }

        $list = $this->list($turnUlid);
        $list[] = [
            'kind' => $event['kind'],
            'data' => $event['data'] ?? null,
            'at' => $event['at'] ?? gmdate('c'),
            'index' => count($list),
        ];
        Cache::put($this->key($turnUlid), $list, 3600);
    }

    public function since(string $turnUlid, int $cursor = 0): array
    {
        $list = $this->list($turnUlid);

        return $cursor <= 0 ? $list : array_values(array_filter($list, fn (array $event): bool => $event['index'] >= $cursor));
    }

    /** @return list<array{kind: string, data?: mixed, at?: string, index: int}> */
    private function list(string $turnUlid): array
    {
        return Cache::get($this->key($turnUlid), []);
    }

    private function key(string $turnUlid): string
    {
        return 'e2e-progress:'.$turnUlid;
    }
}

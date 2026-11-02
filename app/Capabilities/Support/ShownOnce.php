<?php

namespace App\Capabilities\Support;

/**
 * An output that carries a secret to show once, such as a new token's (CAP-021).
 * The idempotency store keeps the output without it ({@see ShownOnceIdempotencyStore}),
 * so a replay (CAP-014) answers with the rest and never shows the secret again.
 */
interface ShownOnce
{
    /** @return array<string, mixed> the output on the wire */
    public function toArray(): array;

    /**
     * @param  array<string, mixed>  $data  the output on the wire
     * @return array<string, mixed> the same output, its secret cleared
     */
    public static function withoutSecret(array $data): array;
}

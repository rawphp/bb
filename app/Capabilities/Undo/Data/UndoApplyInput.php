<?php

namespace App\Capabilities\Undo\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** undo_apply: the undo token a reversible write returned (CAP-013). */
final class UndoApplyInput extends CapabilityData
{
    public function __construct(
        public readonly string $token,
    ) {}

    public static function rules(): array
    {
        return ['token' => ['ulid']];
    }
}

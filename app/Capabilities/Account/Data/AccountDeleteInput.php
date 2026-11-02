<?php

namespace App\Capabilities\Account\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** account_delete: deletes the account and all its data (ACC-005). */
final class AccountDeleteInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'the word DELETE, typed')]
        public readonly string $confirmation,
    ) {}
}

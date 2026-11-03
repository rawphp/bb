<?php

namespace App\Domain\Onboarding;

use App\Domain\Safety\ScreeningItem;

/**
 * One line of the screening list (SAFE-001). A measured item (age, height, weight, BMI) is locked: step 2's answers
 * tick it, never the user.
 */
final readonly class ScreeningRow
{
    public function __construct(
        public ScreeningItem $item,
        public string $label,
        public ?string $detail,
        public bool $isOn,
        public bool $isLocked,
    ) {}
}

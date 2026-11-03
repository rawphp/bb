<?php

namespace App\Domain\Onboarding;

/** ONB-021: the advice to start with Maintenance first, and the button that does. */
final readonly class MaintenanceFirstAdvice
{
    public function __construct(
        public string $text,
        public string $actionTitle,
    ) {}
}

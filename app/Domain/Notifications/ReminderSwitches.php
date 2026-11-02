<?php

namespace App\Domain\Notifications;

/** NOTIF-007: one on/off switch per kind. A kind never switched keeps its default. */
final readonly class ReminderSwitches
{
    /** @param  array<string, bool>  $overrides  kind value => on */
    public function __construct(private array $overrides = []) {}

    public function isOn(ReminderKind $kind): bool
    {
        return $this->overrides[$kind->value] ?? $kind->isOnByDefault();
    }

    public function with(ReminderKind $kind, bool $on): self
    {
        return new self([...$this->overrides, $kind->value => $on]);
    }
}

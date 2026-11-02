<?php

namespace App\Domain\Progression;

/** WO-021: a set's type and, for a regular set, its variant. Stored as a `type` and a `variant` column. */
enum WorkoutSetType: string
{
    case RegularStraight = 'regular_straight';
    case RegularDown = 'regular_down';
    case RegularAscending = 'regular_ascending';
    case Myorep = 'myorep';
    case MyorepMatch = 'myorep_match';

    /** A stored row's type; a regular set without a variant is Straight. */
    public static function stored(string $type, ?string $variant): self
    {
        return match ($type) {
            'myorep' => self::Myorep,
            'myorep_match' => self::MyorepMatch,
            default => self::tryFrom("regular_{$variant}") ?? self::RegularStraight,
        };
    }

    /** @return list<self> WO-022: the sets an exercise's Add set offers, one per type */
    public static function addable(): array
    {
        return [self::RegularStraight, self::Myorep, self::MyorepMatch];
    }

    public function type(): string
    {
        return match ($this) {
            self::Myorep => 'myorep',
            self::MyorepMatch => 'myorep_match',
            default => 'regular',
        };
    }

    public function variant(): ?string
    {
        return match ($this) {
            self::RegularStraight => 'straight',
            self::RegularDown => 'down',
            self::RegularAscending => 'ascending',
            default => null,
        };
    }

    /** WO-021: the badge on a myorep row. */
    public function badge(): ?string
    {
        return match ($this) {
            self::Myorep => 'M',
            self::MyorepMatch => 'MM',
            default => null,
        };
    }
}

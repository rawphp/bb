<?php

namespace App\Domain\Learn;

/** The groups of the Learn index, in order. */
enum LearnSection
{
    case UsingTheApp;
    case Diet;
    case Training;
    case Together;

    public function title(): string
    {
        return match ($this) {
            self::UsingTheApp => 'Using the app',
            self::Diet => 'Diet',
            self::Training => 'Training',
            self::Together => 'Training and diet together',
        };
    }

    /** @return list<LearnTopic> in the index's order */
    public function topics(): array
    {
        return array_values(array_filter(LearnTopic::cases(), fn (LearnTopic $topic): bool => $topic->section() === $this));
    }
}

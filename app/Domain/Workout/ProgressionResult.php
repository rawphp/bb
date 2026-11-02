<?php

namespace App\Domain\Workout;

/** The day's new slot counts after {@see ProgressionApply}, what they were, and the UI-010 toast lines. */
final readonly class ProgressionResult
{
    /**
     * @param  array<string, int>  $slotSets  the day's slot counts after the apply, by slot id
     * @param  array<string, int>  $previous  what the day held before it
     * @param  array<string, int>  $changes  net sets gained or lost per muscle, in the day's order; no change is left out
     * @param  list<string>  $lines  the toast lines, empty when nothing changed
     */
    public function __construct(
        public int $dayIndex,
        public array $slotSets,
        public array $previous,
        public array $changes,
        public array $lines,
    ) {}

    public function changed(): bool
    {
        return $this->slotSets !== $this->previous;
    }

    /**
     * The rotation with the day's new counts. Slots are matched by place, so a stored day whose slots carry no ids
     * takes them too.
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    public function applying(array $days): array
    {
        if (! isset($days[$this->dayIndex])) {
            return $days;
        }
        $counts = array_values($this->slotSets);
        foreach (array_keys($days[$this->dayIndex]['slots'] ?? []) as $position => $key) {
            if (isset($counts[$position])) {
                $days[$this->dayIndex]['slots'][$key]['sets'] = $counts[$position];
            }
        }

        return $days;
    }
}

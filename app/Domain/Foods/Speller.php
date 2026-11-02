<?php

namespace App\Domain\Foods;

/** "Did you mean ...?" (FOOD-024): corrects query terms against a vocabulary of food-name words. */
final class Speller
{
    /** @var array<string, int> word => how many names use it, sorted by word */
    private array $frequency;

    /** @param  list<string>  $words  vocabulary; repeats count as frequency */
    public function __construct(array $words)
    {
        $this->frequency = array_count_values($words);
        ksort($this->frequency, SORT_STRING);
    }

    /** @param  iterable<string>  $names */
    public static function fromNames(iterable $names): self
    {
        return new self(self::words($names));
    }

    /**
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public static function words(iterable $names): array
    {
        $words = [];
        foreach ($names as $name) {
            foreach (SearchTerms::of($name) as $word) {
                if (mb_strlen($word) >= 3) {
                    $words[] = $word;
                }
            }
        }

        return $words;
    }

    /** The lowercased query with each unknown term swapped for its nearest known word, when one is close enough. */
    public function correct(string $query): string
    {
        return implode(' ', array_map($this->nearest(...), SearchTerms::of($query)));
    }

    private function nearest(string $term): string
    {
        if (isset($this->frequency[$term])) {
            return $term;
        }

        $allowed = mb_strlen($term) <= 4 ? 1 : 2;
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($this->frequency as $word => $count) {
            $word = (string) $word;
            $distance = levenshtein($term, $word);
            if ($distance > $allowed) {
                continue;
            }
            // Words are sorted, so on equal distance and frequency the first (alphabetical) wins.
            if ($distance < $bestDistance || ($distance === $bestDistance && $count > $this->frequency[$best])) {
                $best = $word;
                $bestDistance = $distance;
            }
        }

        return $best ?? $term;
    }
}

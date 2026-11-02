<?php

namespace App\Domain\Foods;

/**
 * How a food search query becomes terms (FOOD-029): lowercase words, and the MySQL
 * boolean-mode full-text query for the terms the index can serve.
 */
final class SearchTerms
{
    /** InnoDB ignores full-text tokens shorter than this. */
    public const MIN_TOKEN = 3;

    /** InnoDB's default full-text stopwords: they are never indexed, so "+the*" matches nothing. */
    public const STOPWORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how',
        'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who',
        'will', 'with', 'und', 'www'];

    /** @return list<string> */
    public static function of(string $query): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The terms without stopwords (as the full-text index drops them), unless only stopwords remain.
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    public static function meaningful(array $terms): array
    {
        $meaningful = self::withoutStopwords($terms);

        return $meaningful === [] ? $terms : $meaningful;
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    public static function withoutStopwords(array $terms): array
    {
        return array_values(array_filter($terms, fn (string $t) => ! in_array($t, self::STOPWORDS, true)));
    }

    /**
     * MySQL boolean-mode query for the terms the full-text index can serve (not too short, not a stopword); null when there are none.
     *
     * @param  list<string>  $terms
     */
    public static function booleanQuery(array $terms): ?string
    {
        $long = array_filter(self::withoutStopwords($terms), fn (string $t) => mb_strlen($t) >= self::MIN_TOKEN);

        return $long === [] ? null : implode(' ', array_map(fn (string $t) => '+'.$t.'*', $long));
    }
}

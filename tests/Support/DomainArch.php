<?php

namespace Tests\Support;

use RuntimeException;

/**
 * The plain-PHP rules every app/Domain folder's ArchTest applies, kept in one place so the folders cannot drift.
 */
final class DomainArch
{
    /** No Eloquent, facades, Carbon, config or clock: the domain is handed every date it uses. */
    public const FORBIDDEN_USES = [
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'now',
        'today',
        'config',
        // Every PHP function that reads the clock when it is not handed a timestamp.
        'time',
        'microtime',
        'hrtime',
        'date',
        'gmdate',
        'idate',
        'getdate',
        'localtime',
        'mktime',
        'gmmktime',
        'strtotime',
        'strftime',
        'gmstrftime',
        'date_create',
        'date_create_immutable',
    ];

    /**
     * Any constructor or static factory of DateTime, DateTimeImmutable or DatePeriod, with or without parentheses:
     * 'today', '' and a partial format all fill in from the clock. A DateTimeZone reads no clock, so it is allowed.
     */
    public const BUILDS_DATE = '/new\s+\\\\?Date(Time(Immutable)?|Period)\b|\bDate(Time(Immutable)?|Period)::/i';

    /**
     * The files in app/Domain/<folder> that build a date.
     *
     * @return list<string> file basenames
     */
    public static function filesThatBuildDates(string $folder): array
    {
        $files = glob(dirname(__DIR__, 2)."/app/Domain/{$folder}/*.php") ?: [];
        if ($files === []) {
            throw new RuntimeException("app/Domain/{$folder} has no PHP files to check");
        }

        return array_values(array_map('basename', array_filter(
            $files, fn (string $file) => preg_match(self::BUILDS_DATE, (string) file_get_contents($file)) === 1,
        )));
    }
}

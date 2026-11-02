<?php

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

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
        // Every PHP function that reads the clock when it is not handed a timestamp, or fills a partial format from it.
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
        'date_create_from_format',
        'date_create_immutable_from_format',
        'gettimeofday',
        'unixtojd',
        'easter_date',
        'easter_days',
    ];

    /**
     * Any constructor or static factory of DateTime, DateTimeImmutable or DatePeriod, with or without parentheses:
     * 'today', '' and a partial format all fill in from the clock. A DateTimeZone reads no clock, so it is allowed.
     */
    public const BUILDS_DATE = '/new\s+\\\\?Date(Time(Immutable)?|Period)\b|\bDate(Time(Immutable)?|Period)::/i';

    /**
     * The files in app/Domain/<folder>, subfolders included, that build a date.
     *
     * @return list<string> paths relative to the folder, sorted
     */
    public static function filesThatBuildDates(string $folder): array
    {
        return self::filesThatBuildDatesIn(dirname(__DIR__, 2)."/app/Domain/{$folder}");
    }

    /**
     * @return list<string> paths relative to $root, sorted
     */
    public static function filesThatBuildDatesIn(string $root): array
    {
        if (! is_dir($root)) {
            throw new RuntimeException("{$root} does not exist");
        }

        $checked = 0;
        $building = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $checked++;
            if (preg_match(self::BUILDS_DATE, (string) file_get_contents($file->getPathname())) === 1) {
                $building[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        if ($checked === 0) {
            throw new RuntimeException("{$root} has no PHP files to check");
        }
        sort($building);

        return $building;
    }
}

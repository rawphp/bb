<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs every database/seeders/<Area>Seeder.php in name order, so a new area
 * adds its own seeder file and never edits this one.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(self::areaSeeders());
    }

    /** @return list<class-string<Seeder>> */
    public static function areaSeeders(): array
    {
        $files = glob(__DIR__.'/*Seeder.php') ?: [];
        sort($files);

        $seeders = [];
        foreach ($files as $file) {
            $class = __NAMESPACE__.'\\'.basename($file, '.php');
            if ($class !== self::class) {
                $seeders[] = $class;
            }
        }

        /** @var list<class-string<Seeder>> $seeders */
        return $seeders;
    }
}

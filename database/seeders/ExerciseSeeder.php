<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

class ExerciseSeeder extends Seeder
{
    /**
     * Upsert the shared exercise library by name; safe to re-run.
     * Sets video_url only for exercises named in exercise_videos.php and leaves the rest alone.
     */
    public function run(): void
    {
        $videos = require __DIR__.'/data/exercise_videos.php';

        foreach (require __DIR__.'/data/exercises.php' as $row) {
            if (isset($videos[$row['name']])) {
                $row['video_url'] = $videos[$row['name']];
            }

            Exercise::updateOrCreate(['user_id' => null, 'name' => $row['name']], $row);
        }
    }
}

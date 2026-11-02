<?php

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Enums\SfrRating;
use App\Models\Exercise;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ExerciseSeeder;

it('EX-001 seeds at least 300 library exercises with every field', function () {
    $this->seed(ExerciseSeeder::class);

    $library = Exercise::whereNull('user_id')->get();

    expect($library->count())->toBeGreaterThanOrEqual(300)
        ->and($library->pluck('name')->unique()->count())->toBe($library->count());

    foreach ($library as $exercise) {
        expect($exercise->name)->not->toBeEmpty()
            ->and($exercise->muscle_group)->toBeInstanceOf(MuscleGroup::class)
            ->and($exercise->equipment)->toBeInstanceOf(Equipment::class)
            ->and($exercise->load_type)->toBeInstanceOf(ExerciseLoadType::class)
            ->and($exercise->sfr_rating)->toBeInstanceOf(SfrRating::class)
            ->and($exercise->is_compound)->toBeBool();
    }
});

it('EX-001 every exercise in the video file is a seeded library exercise', function () {
    $this->seed(ExerciseSeeder::class);

    $library = Exercise::whereNull('user_id')->pluck('name')->all();

    foreach (array_keys(require database_path('seeders/data/exercise_videos.php')) as $name) {
        expect($library)->toContain($name);
    }
});

it('EX-001 every video link is a YouTube watch url', function () {
    foreach (require database_path('seeders/data/exercise_videos.php') as $name => $url) {
        expect($url)->toMatch('~^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$~', "{$name} has a bad link");
    }
});

it('EX-001 seeds at least 250 library exercises with a video, exactly as the video file has them', function () {
    $this->seed(ExerciseSeeder::class);
    $videos = require database_path('seeders/data/exercise_videos.php');

    expect(Exercise::whereNull('user_id')->whereNotNull('video_url')->count())->toBeGreaterThanOrEqual(250)
        ->and(Exercise::whereNull('user_id')->whereNotNull('video_url')->pluck('video_url', 'name')->all())
        ->toEqualCanonicalizing($videos);
});

it('EX-001 re-seeding updates named links and keeps links the file does not name', function () {
    $this->seed(ExerciseSeeder::class);
    $videos = require database_path('seeders/data/exercise_videos.php');
    $named = array_key_first($videos);
    $unnamed = Exercise::whereNull('user_id')->whereNotIn('name', array_keys($videos))->firstOrFail();

    Exercise::where('name', $named)->update(['video_url' => 'https://www.youtube.com/watch?v=stale000000']);
    $unnamed->update(['video_url' => 'https://www.youtube.com/watch?v=kept0000000']);

    $this->seed(ExerciseSeeder::class);

    expect(Exercise::where('name', $named)->value('video_url'))->toBe($videos[$named])
        ->and($unnamed->fresh()?->video_url)->toBe('https://www.youtube.com/watch?v=kept0000000');
});

it('EX-001 seeding twice does not duplicate library exercises', function () {
    $this->seed(ExerciseSeeder::class);
    $count = Exercise::count();

    $this->seed(ExerciseSeeder::class);

    expect(Exercise::count())->toBe($count);
});

it('EX-001 leaves a user\'s custom exercise with a library name alone', function () {
    $custom = Exercise::factory()->custom()->create(['name' => 'Barbell Bench Press', 'video_url' => null]);

    $this->seed(ExerciseSeeder::class);

    expect($custom->fresh()?->video_url)->toBeNull()
        ->and(Exercise::whereNull('user_id')->where('name', 'Barbell Bench Press')->count())->toBe(1);
});

it('EX-001 is part of the default seed', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Exercise::whereNull('user_id')->count())->toBeGreaterThanOrEqual(300);
});

it('MESO-033 every muscle group has at least 8 library exercises', function () {
    $this->seed(ExerciseSeeder::class);

    expect(MuscleGroup::cases())->toHaveCount(14);

    foreach (MuscleGroup::cases() as $group) {
        expect(Exercise::whereNull('user_id')->where('muscle_group', $group->value)->count())
            ->toBeGreaterThanOrEqual(8, "{$group->value} has too few exercises");
    }
});

it('EX-007 seeded rep ranges use one of the three bands', function () {
    $this->seed(ExerciseSeeder::class);

    $bands = ['5-10', '10-20', '20-30'];

    foreach (Exercise::whereNull('user_id')->get() as $exercise) {
        expect($bands)->toContain("{$exercise->rep_range_min}-{$exercise->rep_range_max}");
    }
});

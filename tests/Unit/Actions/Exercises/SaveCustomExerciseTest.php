<?php

use App\Actions\Exercises\SaveCustomExercise;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Enums\SfrRating;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is the exercises row it keeps, so it runs on the database.
uses(RefreshDatabase::class);

it('EX-004 creates the user\'s custom exercise with the server defaults: SFR medium, 10 to 20 reps, not compound, no video', function () {
    $user = User::factory()->create();

    $exercise = (new SaveCustomExercise)($user, null, '  Landmine Press  ', 'frontDelts', 'barbell', 'barbell');

    expect($exercise->fresh())
        ->user_id->toBe($user->id)
        ->name->toBe('Landmine Press')
        ->muscle_group->toBe(MuscleGroup::FrontDelts)
        ->equipment->toBe(Equipment::Barbell)
        ->load_type->toBe(ExerciseLoadType::Barbell)
        ->sfr_rating->toBe(SfrRating::Medium)
        ->rep_range_min->toBe(10)
        ->rep_range_max->toBe(20)
        ->is_compound->toBeFalse()
        ->video_url->toBeNull();
});

it('EX-004 EX-005 edits the name, muscle group, equipment and load type in place, and nothing else', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->custom($user)->create([
        'name' => 'Press', 'muscle_group' => 'chest', 'equipment' => 'barbell', 'load_type' => 'barbell',
        'sfr_rating' => 'high', 'rep_range_min' => 5, 'rep_range_max' => 8, 'is_compound' => true,
    ]);
    $count = Exercise::query()->count();

    (new SaveCustomExercise)($user, $exercise, 'Flat Press', 'triceps', 'dumbbell', 'dumbbell');

    expect(Exercise::query()->count())->toBe($count)
        ->and($exercise->fresh())
        ->name->toBe('Flat Press')
        ->muscle_group->toBe(MuscleGroup::Triceps)
        ->equipment->toBe(Equipment::Dumbbell)
        ->load_type->toBe(ExerciseLoadType::Dumbbell)
        ->sfr_rating->toBe(SfrRating::High)
        ->rep_range_min->toBe(5)
        ->is_compound->toBeTrue();
});

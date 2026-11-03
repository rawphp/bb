<?php

use App\Actions\Exercises\SetExerciseNote;
use App\Models\Exercise;
use App\Models\ExerciseNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('EX-010 pins the trimmed note, one row per user and exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    (new SetExerciseNote)($user, $exercise->id, '  Pause on the chest  ');
    (new SetExerciseNote)($user, $exercise->id, 'Pause longer');

    expect(ExerciseNote::query()->where('user_id', $user->id)->where('exercise_id', $exercise->id)->pluck('note')->all())->toBe(['Pause longer']);
});

it('EX-010 clears the note on null or on a note that is blank once trimmed', function (?string $note) {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    ExerciseNote::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id]);

    (new SetExerciseNote)($user, $exercise->id, $note);

    expect(ExerciseNote::query()->count())->toBe(0);
})->with(['null' => [null], 'spaces' => ['   ']]);

it('EX-010 keeps another user\'s note on the same exercise', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $exercise = Exercise::factory()->create();
    ExerciseNote::factory()->create(['user_id' => $other->id, 'exercise_id' => $exercise->id, 'note' => 'Theirs']);

    (new SetExerciseNote)($user, $exercise->id, null);
    (new SetExerciseNote)($user, $exercise->id, 'Mine');

    expect(ExerciseNote::query()->where('user_id', $other->id)->value('note'))->toBe('Theirs')
        ->and(ExerciseNote::query()->count())->toBe(2);
});

<?php

namespace App\Capabilities\Exercises;

use App\Actions\Exercises\SetExerciseNote;
use App\Capabilities\Exercises\Data\ExerciseNoteSetInput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Exercise;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ExerciseNoteSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetExerciseNote $note) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('exercise_note_set', affects: ['exercise_show', 'workout_show'])
            ->description('Pin a note to an exercise, such as "pause on the chest"; it shows every time the exercise appears. A null or blank note clears it. For a note on one session only, use workout_exercise_note_set.')
            ->cli('exercise', 'note-set')
            ->input(ExerciseNoteSetInput::class)
            ->run(function (ExerciseNoteSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $id = ExerciseReads::id($input->exercise_id);

                ($this->note)($user, $id, $input->note);

                return new WriteResult(invalidates: [ExerciseReads::show($id), ...ExerciseReads::workouts($user, $id)]);
            });
    }

    public function owned(User $user): array
    {
        return ['exercise_id' => Exercise::query()->visibleTo($user)];
    }
}

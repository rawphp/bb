<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Progress\Data\ExerciseHistoryShowInput;
use App\Capabilities\Progress\Data\ExerciseHistoryShowOutput;
use App\Capabilities\Reads\Progress\ExerciseHistoryPayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Progress\ExerciseHistorySnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ExerciseHistoryShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('exercise_history_show')
            ->description('Show one exercise\'s history: every session in which the user logged a set of it, 20 sessions at a time, newest first, each with its date, its sets as weight times reps in the user\'s unit, and the best estimated one rep max (e1RM) of the session, the set that reached it marked. Use it to see how a lift has progressed, and when next_before is set, pass it as before to read the older sessions. It changes nothing.')
            ->cli('exercise', 'history')
            ->input(ExerciseHistoryShowInput::class)
            ->output(ExerciseHistoryShowOutput::class)
            ->run(function (ExerciseHistoryShowInput $input, CapabilityContext $context): ExerciseHistoryShowOutput {
                /** @var User $user */
                $user = $context->user();
                $history = ExerciseHistorySnapshot::load($user, $input->exercise_id, $input->before)
                    ?? throw (new ModelNotFoundException)->setModel(Exercise::class, [$input->exercise_id]);

                return ExerciseHistoryPayload::build($history->exercise, $history->sets, $history->unit, $history->nextBefore);
            });
    }

    public function owned(User $user): array
    {
        return [
            'exercise_id' => Exercise::query()->visibleTo($user),
            'before' => WorkoutSession::query()->where('user_id', $user->id),
        ];
    }
}

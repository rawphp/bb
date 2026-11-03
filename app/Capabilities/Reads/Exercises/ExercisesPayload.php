<?php

namespace App\Capabilities\Reads\Exercises;

use App\Capabilities\Exercises\Data\ExerciseChoice;
use App\Capabilities\Exercises\Data\ExerciseLibraryRow;
use App\Capabilities\Exercises\Data\ExercisesShowOutput;
use App\Capabilities\Reads\Train\TrainWords;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/** The exercise library (EX-001, EX-003, EX-013): every exercise sorted by name, and the choices for the filters and the custom sheet. */
final class ExercisesPayload
{
    /**
     * @param  Collection<int, Exercise>  $exercises  the ones the user can see
     * @param  array<int, ?DateTimeImmutable>  $lastUsed  exercise id => the newest session that has a logged set of it
     */
    public static function build(Collection $exercises, array $lastUsed): ExercisesShowOutput
    {
        return new ExercisesShowOutput(
            exercises: $exercises
                ->sort(fn (Exercise $a, Exercise $b) => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id])
                ->values()
                ->map(fn (Exercise $exercise) => new ExerciseLibraryRow(
                    id: (string) $exercise->id,
                    name: $exercise->name,
                    detail: $exercise->muscle_group->title().' · '.$exercise->equipment->title(),
                    muscle_group: $exercise->muscle_group->value,
                    equipment: $exercise->equipment->value,
                    last_used_text: isset($lastUsed[$exercise->id]) ? 'Last used '.TrainWords::date($lastUsed[$exercise->id]) : 'Never used',
                    has_video: self::videoUrl($exercise->video_url) !== null,
                ))
                ->all(),
            muscle_groups: array_map(fn (MuscleGroup $case) => new ExerciseChoice($case->value, $case->title()), MuscleGroup::cases()),
            equipment: array_map(fn (Equipment $case) => new ExerciseChoice($case->value, $case->title()), Equipment::cases()),
            load_types: array_map(fn (ExerciseLoadType $case) => new ExerciseChoice($case->value, $case->title()), ExerciseLoadType::cases()),
        );
    }

    /** EX-001: the link, only when it is an http or https address with a host; a script, a file or a blank is no video. */
    public static function videoUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        $parts = parse_url($url);

        return $url !== '' && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && ($parts['host'] ?? '') !== ''
            ? $url
            : null;
    }
}

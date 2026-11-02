<?php

namespace App\Domain\Progression;

use App\Domain\Model\MuscleGroup;

/** Underperformance flags (PROG-050, PROG-051). */
final class Underperformance
{
    /** PROG-050: a set misses when it falls this many reps or more short of its target. */
    public const MISSED_BY_REPS = 2;

    /** PROG-050: this many sessions running that trained the muscle. */
    public const SESSIONS_RUNNING = 2;

    /**
     * PROG-050: most of the muscle's sets in the session missed their target. False when the session did not train
     * the muscle.
     *
     * @param  list<LoggedExercise>  $session
     */
    public static function missed(MuscleGroup $muscle, array $session): bool
    {
        $sets = self::setsOf($muscle, $session);

        return $sets !== [] && Most::of($sets, fn (LoggedSet $set) => $set->targetReps - $set->reps >= self::MISSED_BY_REPS);
    }

    /**
     * PROG-050: muscles whose latest sessions running all missed. Sessions are oldest first; a session that did not
     * train a muscle does not count for it. Muscles come in MuscleGroup order.
     *
     * @param  list<list<LoggedExercise>>  $sessions
     * @return list<MuscleGroup>
     */
    public static function flaggedMuscles(array $sessions): array
    {
        return array_values(array_filter(MuscleGroup::cases(), function (MuscleGroup $muscle) use ($sessions) {
            $trained = array_values(array_filter($sessions, fn (array $session) => self::trains($session, $muscle)));
            $latest = array_slice($trained, -self::SESSIONS_RUNNING);

            return count($latest) === self::SESSIONS_RUNNING
                && array_all($latest, fn (array $session) => self::missed($muscle, $session));
        }));
    }

    /**
     * PROG-052: muscles flagged by a session of this week. $sessions is the whole history, oldest first, ending with
     * this week's $sessionsThisWeek sessions, so a run can start last week; a flag only counts when a session this
     * week trained the muscle. Muscles come in MuscleGroup order.
     *
     * @param  list<list<LoggedExercise>>  $sessions
     * @return list<MuscleGroup>
     */
    public static function flaggedMusclesThisWeek(array $sessions, int $sessionsThisWeek): array
    {
        $flagged = [];
        for ($index = max(0, count($sessions) - $sessionsThisWeek); $index < count($sessions); $index++) {
            foreach (self::flaggedMuscles(array_slice($sessions, 0, $index + 1)) as $muscle) {
                if (self::trains($sessions[$index], $muscle)) {
                    $flagged[$muscle->value] = true;
                }
            }
        }

        return array_values(array_filter(MuscleGroup::cases(), fn (MuscleGroup $muscle) => isset($flagged[$muscle->value])));
    }

    /** PROG-051: hold sets and repeat the weight. */
    public static function advice(MuscleGroup $muscle, int $sets, float $lastWeight): UnderperformanceAdvice
    {
        return new UnderperformanceAdvice(
            $muscle, $sets, $lastWeight,
            $muscle->title().' missed its rep targets '.self::SESSIONS_RUNNING.' sessions running. Hold sets and repeat the weight to recover.',
        );
    }

    /** @param list<LoggedExercise> $session */
    private static function trains(array $session, MuscleGroup $muscle): bool
    {
        return array_any($session, fn (LoggedExercise $exercise) => $exercise->muscle === $muscle);
    }

    /**
     * @param  list<LoggedExercise>  $session
     * @return list<LoggedSet>
     */
    private static function setsOf(MuscleGroup $muscle, array $session): array
    {
        $sets = [];
        foreach ($session as $exercise) {
            if ($exercise->muscle === $muscle) {
                array_push($sets, ...$exercise->sets);
            }
        }

        return $sets;
    }
}

<?php

namespace App\Capabilities\Reads\Workout;

use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Capabilities\Train\Data\Measure;
use App\Capabilities\Workout\Data\WorkoutChoice;
use App\Capabilities\Workout\Data\WorkoutExercise;
use App\Capabilities\Workout\Data\WorkoutFeedback;
use App\Capabilities\Workout\Data\WorkoutFeedbackQuestion;
use App\Capabilities\Workout\Data\WorkoutHelpSection;
use App\Capabilities\Workout\Data\WorkoutLookBackRow;
use App\Capabilities\Workout\Data\WorkoutRepRow;
use App\Capabilities\Workout\Data\WorkoutSet;
use App\Capabilities\Workout\Data\WorkoutShowOutput;
use App\Capabilities\Workout\Data\WorkoutWarmUp;
use App\Capabilities\Workout\Data\WorkoutWarmUpStep;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\JointPain;
use App\Domain\Model\Pump;
use App\Domain\Model\Session;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Placement\ClockTime;
use App\Domain\Progression\FeedbackSheet;
use App\Domain\Progression\RepTable;
use App\Domain\Progression\RepTableRow;
use App\Domain\Progression\SetPlan;
use App\Domain\Progression\WorkoutStatus;
use App\Models\DayTarget;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Workout\WorkoutBlock;
use App\Support\Workout\WorkoutSetRow;
use App\Support\Workout\WorkoutSnapshot;

/**
 * The workout screen (rp-clone WorkoutModel, WO-001 to WO-052, NAV-004) from a {@see WorkoutSnapshot}, with
 * everything the session needs offline (OFF-020): each set's target and rep table (CAP-016), the Feedback sheets and
 * when they fall due, the notes and the next meal. The view computes nothing.
 */
final class WorkoutPayload
{
    public static function build(WorkoutSnapshot $s): WorkoutShowOutput
    {
        $status = $s->status();
        $editable = $status->isEditable();
        $blocks = $s->blocks();
        $plan = $s->plan();
        $rir = $s->rir();
        $isDeload = $s->isDeload();
        $sheets = self::feedback($s);
        // WO-040: the same rules workout_finish refuses by.
        $pending = $editable && $s->pendingFeedback() !== [];
        $sets = array_merge(...array_map(fn (WorkoutBlock $block) => $block->sets, $blocks));
        $allDone = $s->allSetsDone();
        $anyDone = array_filter($sets, fn (WorkoutSetRow $set) => $set->isDone()) !== [];
        $date = $s->date();

        $exercises = [];
        foreach ($blocks as $index => $block) {
            $exercises[] = self::exercise($s, $block, $index, count($blocks), $editable, $rir);
        }

        return new WorkoutShowOutput(
            workout_id: $s->workoutId(),
            mesocycle_id: (string) $s->mesocycle->id,
            title: implode(' · ', array_filter(["Week {$s->week}", "Day {$s->day}", $plan->name, $date?->format('D j M')])),
            day_label: $plan->name,
            status: $status->value,
            is_editable: $editable,
            active_bar_text: $status === WorkoutStatus::InProgress
                ? $plan->name.' · '.count(array_filter($blocks, fn (WorkoutBlock $b) => $b->isDone())).' of '.count($blocks).' exercises'
                : null,
            weight_unit: $s->unit()->value,
            deload_banner: $isDeload ? new Status(WorkoutWords::DELOAD, Tone::Info->value) : null,
            feedback_banner: $pending ? new Status(WorkoutWords::FEEDBACK_REQUIRED, Tone::Warning->value) : null,
            can_finish: $editable && $allDone && ! $pending,
            next_meal: self::nextMeal($s),
            warm_up: $editable && $blocks !== [] && ! $anyDone ? new WorkoutWarmUp(
                workout_exercise_id: $blocks[0]->id,
                title: WorkoutWords::WARM_UP_TITLE,
                steps: array_map(fn (string $text) => new WorkoutWarmUpStep($text), WorkoutWords::WARM_UP_STEPS),
                note: WorkoutWords::WARM_UP_NOTE,
            ) : null,
            rir_info: new WorkoutHelpSection(WorkoutWords::RIR_TITLE, WorkoutWords::rir($rir), 'rir'),
            exercises: $exercises,
            feedback: $sheets,
            set_type_choices: WorkoutWords::setTypeChoices(),
            add_set_choices: WorkoutWords::addSetChoices(),
            help: WorkoutWords::help(),
            swap_advice: WorkoutWords::SWAP_ADVICE,
            look_back: self::lookBack($s),
        );
    }

    // MARK: exercises and sets (WO-002 to WO-007, WO-021, WO-023)

    private static function exercise(WorkoutSnapshot $s, WorkoutBlock $block, int $index, int $count, bool $editable, ?int $rir): WorkoutExercise
    {
        $exercise = $block->exercise;
        $unit = $s->unit()->value;
        $lastWeek = $s->lastWeekLoads($exercise);
        $sets = [];
        foreach ($block->sets as $number => $row) {
            $sets[] = self::set($s, $block, $row, $number, $rir, SetPlan::lastWeekFor($lastWeek, $number)?->weight);
        }

        return new WorkoutExercise(
            id: $block->id,
            exercise_id: (string) $exercise->id,
            name: $exercise->name,
            detail: $exercise->equipment->title()." · {$exercise->rep_range_min}–{$exercise->rep_range_max} reps",
            muscle_group: $exercise->muscle_group->value,
            weight_label: match ($exercise->load_type) {
                ExerciseLoadType::Dumbbell => "Weight of one dumbbell ({$unit})",
                ExerciseLoadType::Bodyweight => "Added weight ({$unit})",
                default => "Weight ({$unit})",
            },
            pinned_note: $s->pinnedNote($exercise),
            session_note: $block->note,
            recovery_note: ($note = $s->recoveryNote($exercise, max(1, count($block->sets)))) === null ? null : new Status($note, Tone::Neutral->value),
            swap_suggestion: ($swap = $s->swapSuggestion($exercise)) === null ? null : new Status($swap, Tone::Warning->value),
            carries_first_weight: $editable && $exercise->load_type !== ExerciseLoadType::Bodyweight && $block->sets !== []
                && array_filter($block->sets, fn (WorkoutSetRow $set) => $set->targetKg !== null) === [],
            can_move_up: $index > 0,
            can_move_down: $index < $count - 1,
            sets: $sets,
        );
    }

    /**
     * rp-clone restoredSet: the fields hold what was typed or logged, else the target (WO-004). A weight moved off
     * the target with no reps typed shows the rep table's reps for it (WO-005, PROG-024, CAP-016), as the view does
     * offline; a weight not in the table, too far from the plan or from last week's (PROG-025), shows only the RIR.
     */
    private static function set(WorkoutSnapshot $s, WorkoutBlock $block, WorkoutSetRow $row, int $index, ?int $rir, ?float $lastWeekWeight): WorkoutSet
    {
        $exercise = $block->exercise;
        $weightKg = $row->actualKg ?? $row->targetKg;
        if (in_array($exercise->load_type, [ExerciseLoadType::Bodyweight, ExerciseLoadType::BodyweightPlusLoad], true) && $weightKg !== null && abs($weightKg) < 1e-9) {
            $weightKg = null;
        }
        $target = $row->targetKg === null ? null : round($s->display($row->targetKg), 4);
        $table = RepTable::rows($target, $row->targetReps, $rir, $s->rules($exercise), $s->isFatLoss(), $lastWeekWeight);
        $reps = $row->actualReps;
        if ($reps === null && self::showsTarget($row, $weightKg)) {
            $reps = $row->targetReps;
        } elseif ($reps === null && $weightKg !== null) {
            $reps = RepTable::repsAt($table, $s->display($weightKg));
        }

        return new WorkoutSet(
            id: $row->id,
            number: $index + 1,
            set_type: $row->type->value,
            badge: $row->type->badge(),
            weight: $weightKg === null ? null : self::measure($s, $s->display($weightKg)),
            reps: $reps,
            target_weight: $target === null ? null : self::measure($s, $target),
            target_text: $row->targetReps === null ? null
                : implode(' ', array_filter([$target === null ? null : WorkoutWords::weight($target, $s->unit()), "× {$row->targetReps}"])),
            reps_placeholder: $rir === null ? 'Reps' : "{$rir} RIR",
            rep_table: array_map(fn (RepTableRow $r) => new WorkoutRepRow(self::measure($s, $r->weight), $r->reps), $table),
            off_table_text: $table === [] ? null : "{$rir} RIR, just log reps",
            is_logged: $row->isLogged,
            is_skipped: $row->isSkipped,
        );
    }

    /** WO-004: the planned weight is in use. */
    private static function showsTarget(WorkoutSetRow $row, ?float $weightKg): bool
    {
        if ($row->targetReps === null && $row->targetKg === null) {
            return false;
        }

        return match (true) {
            $weightKg === null && $row->targetKg === null => true,
            $weightKg !== null && $row->targetKg !== null => abs($weightKg - $row->targetKg) < 0.001,
            default => false,
        };
    }

    private static function measure(WorkoutSnapshot $s, float $value): Measure
    {
        $value = round($value, 2);

        return new Measure($value, $s->unit()->value, WorkoutWords::weight($value, $s->unit()));
    }

    // MARK: feedback (FB-001 to FB-009, OFF-020)

    /**
     * @return list<WorkoutFeedback>
     */
    private static function feedback(WorkoutSnapshot $s): array
    {
        // FB-001 to FB-007: the same sheets, answers and soreness rule the feedback and finish writes check against.
        $joints = array_map(fn (JointPain $pain) => $pain->value, $s->jointAnswers());

        return array_map(function (FeedbackSheet $sheet) use ($s, $joints) {
            $row = $s->muscleAnswers($sheet->muscle);
            $soreness = Soreness::tryFrom((string) $row?->soreness)?->value;
            $pump = Pump::tryFrom((string) $row?->pump)?->value;
            $volume = VolumeFeedback::tryFrom((string) $row?->volume)?->value;
            $asks = $s->asksSoreness($sheet->muscle);
            $muscle = mb_strtolower($sheet->muscle->title());

            $questions = [];
            foreach ($sheet->exercises as $exerciseId => $name) {
                $questions[] = new WorkoutFeedbackQuestion('joint', (string) $exerciseId, "How did your joints feel during {$name}?", WorkoutWords::feedbackHelp('joint'),
                    array_map(fn (JointPain $a) => new WorkoutChoice($a->value, $a->label(), $a === JointPain::ALot ? WorkoutWords::PAIN_NOTE : null), JointPain::cases()),
                    $joints[$exerciseId] ?? null);
            }
            if ($asks) {
                $questions[] = new WorkoutFeedbackQuestion('soreness', null, "How sore did you get in your {$muscle} AFTER training them LAST TIME?", WorkoutWords::feedbackHelp('soreness'),
                    array_map(fn (Soreness $a) => new WorkoutChoice($a->value, $a->label(), null), Soreness::cases()), $soreness);
            }
            $questions[] = new WorkoutFeedbackQuestion('pump', null, "How much of a pump did you get today in your {$muscle}?", WorkoutWords::feedbackHelp('pump'),
                array_map(fn (Pump $a) => new WorkoutChoice($a->value, $a->label(), null), Pump::cases()), $pump);
            $questions[] = new WorkoutFeedbackQuestion('volume', null, "How was the volume of work (number of hard sets) for your {$muscle}?", WorkoutWords::feedbackHelp('volume'),
                array_map(fn (VolumeFeedback $a) => new WorkoutChoice($a->value, $a->label(), null), VolumeFeedback::cases()), $volume);

            return new WorkoutFeedback(
                muscle_group: $sheet->muscle->value,
                title: $sheet->muscle->title(),
                due_after_exercise_id: $sheet->dueAfter,
                is_due: $sheet->isDue,
                is_answered: $s->isAnswered($sheet),
                can_edit: ! $s->isLocked($sheet->muscle),
                questions: $questions,
            );
        }, $s->sheets());
    }

    // MARK: next meal (WO-009), look back (WO-008)

    /**
     * WO-009: the first meal of the session's day at or after its planned end, or now when that is later and the
     * session is today.
     */
    private static function nextMeal(WorkoutSnapshot $s): ?string
    {
        $date = $s->date();
        $target = $date === null ? null : $s->dayTargets->first(fn (DayTarget $t) => TrainSnapshot::day($t->date) == $date);
        if ($target === null) {
            return null;
        }
        $cutoffs = [];
        $end = $s->plannedEnd();
        if ($end !== null) {
            $cutoffs[] = (int) $end->format('G') * 60 + (int) $end->format('i');
        }
        if ($date == $s->train->today) {
            $cutoffs[] = (int) $s->now->format('G') * 60 + (int) $s->now->format('i');
        }
        if ($cutoffs === []) {
            return null;
        }
        $cutoff = max($cutoffs);
        $next = null;
        foreach ($target->meals as $index => $meal) {
            $time = ClockTime::fromHhmm((string) ($meal['time'] ?? ''));
            if ($time !== null && $time->minuteOfDay >= $cutoff && ($next === null || $time->minuteOfDay < $next['time']->minuteOfDay)) {
                $next = ['name' => ($meal['name'] ?? null) ?: 'Meal '.($index + 1), 'time' => $time, 'carbs' => (float) ($meal['carbs_g'] ?? 0)];
            }
        }
        if ($next === null) {
            return null;
        }

        return "Next: {$next['name']} at {$next['time']->shortText()} · ".(int) round($next['carbs']).' g carbs';
    }

    /**
     * WO-008: the earlier sessions of this mesocycle that were started or skipped, newest first.
     *
     * @return list<WorkoutLookBackRow>
     */
    private static function lookBack(WorkoutSnapshot $s): array
    {
        $domain = $s->train->domain($s->mesocycle);

        return $s->mine()
            ->filter(fn (WorkoutSession $row) => $row->status() !== WorkoutStatus::NotStarted && [$row->week, $row->day] < [$s->week, $s->day])
            ->map(function (WorkoutSession $row) use ($domain) {
                $plan = $domain->sessionsInWeek($row->week - 1)[$row->day - 1] ?? null;

                return $plan === null ? null : [$row, $plan];
            })
            ->filter()
            ->unique(fn (array $pair) => "{$pair[0]->week}/{$pair[0]->day}")
            ->sortByDesc(fn (array $pair) => $pair[0]->week * 100 + $pair[0]->day)
            ->map(function (array $pair) {
                /** @var array{0: WorkoutSession, 1: Session} $pair */
                [$row, $plan] = $pair;

                return new WorkoutLookBackRow(
                    week: $row->week,
                    day: $row->day,
                    title: "Week {$row->week} · Day {$row->day} · {$plan->name}",
                    status: match ($row->status()) {
                        WorkoutStatus::Skipped => new Status('Skipped', Tone::Neutral->value),
                        WorkoutStatus::Finished => new Status('Done', Tone::Good->value),
                        default => new Status('Started', Tone::Info->value),
                    },
                );
            })
            ->values()->all();
    }
}

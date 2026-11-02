<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\AwayWeekShowOutput;
use App\Capabilities\Reads\Data\Today\AwayCaloriesOption;
use App\Capabilities\Reads\Data\Today\AwayTrainingOption;
use App\Capabilities\Reads\Data\Today\AwayWeekOption;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Plan\AwayWeeks;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\AwayOffer;
use DomainException;

/** The Away sheet for one plan week (rp-clone AwayWeekModel, SCH-060, PLAN-025). */
final class AwayWeekPayload
{
    /** @throws DomainException when the sheet does not offer that week */
    public static function build(AwayWeeks $away, string $weekStart): AwayWeekShowOutput
    {
        $week = $away->weekStartingOn(PlanSnapshot::day($weekStart));
        if ($week === null || ! $away->isOffered($week)) {
            throw new DomainException('That week cannot be marked away.');
        }
        $row = $away->row($week);
        $phase = $away->phase();
        $editing = $row->isAway;
        $deload = $away->deloadOffered([$week]);

        $calories = match (true) {
            $editing => $row->calories === Calories::RestDays ? Calories::RestDays : Calories::Maintenance,
            default => $phase?->type->isFatLoss() ? Calories::Maintenance : Calories::RestDays,
        };
        $net = $away->change($week, true, $calories);
        $asks = $net !== 0 && $phase?->end !== null;

        return new AwayWeekShowOutput(
            is_editing: $editing,
            weeks: array_map(fn (int $offered) => new AwayWeekOption(
                week_start: $away->start($offered)->format('Y-m-d'),
                label: 'Week '.($offered + 1).' · '.$away->start($offered)->format('M j'),
                is_away: $away->row($offered)->isAway,
                selected: $offered === $week,
            ), $editing ? [$week] : $away->offered()),
            training_options: [
                new AwayTrainingOption('pause', 'Pause training', 'Your mesocycle picks up where it stopped, and ends a week later.', true),
                new AwayTrainingOption(
                    'deload',
                    'Make it the deload',
                    $deload ? 'This week becomes the mesocycle\'s deload.' : 'Needs 3 training weeks of the mesocycle before it.',
                    $deload,
                ),
            ],
            calorie_options: [
                new AwayCaloriesOption('rest', 'Rest-day targets', 'Every day eats like a rest day.'),
                new AwayCaloriesOption('maintenance', 'Maintenance', 'Every day eats at maintenance. In Fat loss the week counts as a diet break.'),
            ],
            training: $row->training === Training::Deload ? 'deload' : 'pause',
            calories: AwayOffer::apiCalories($calories),
            notes: $asks ? [new Status(
                $net > 0 ? 'The phase ends later, a week for each diet break.' : 'The phase ends sooner, a week for each diet break undone.',
                Tone::Info->value,
            )] : [],
            shows_keep_phase_end: $asks,
            keep_phase_end: $editing && $row->keepPhaseEnd,
        );
    }
}

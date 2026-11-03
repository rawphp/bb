<?php

namespace App\Capabilities\Reads\Plan;

use App\Capabilities\Plan\Data\PhaseEventOffer;
use App\Capabilities\Plan\Data\PhaseGoalChoice;
use App\Capabilities\Plan\Data\PhaseShowOutput;
use App\Capabilities\Plan\Data\PlanLengthChoice;
use App\Capabilities\Plan\Data\PlanLengthFix;
use App\Capabilities\Plan\Data\PlanTextLine;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Phases\PhaseWarning;
use App\Domain\Phases\PhaseWarnings;
use App\Models\Phase;
use App\Support\Plan\GoalPreview;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use App\Support\Plan\PlanWords;
use DateTimeImmutable;

/**
 * A phase opened from the timeline (PLAN-004, rp-clone's PhaseDetail and PhaseDetailModel): its type, dates, rate,
 * weights and progress, the event countdown and check (PH-042, PH-043, PLAN-028), and what Adjust phase length
 * (PH-006, PLAN-021) and Change goal (PH-009 to PH-012) offer.
 */
final class PhasePayload
{
    public static function build(PlanSnapshot $plan, Phase $phase): PhaseShowOutput
    {
        $changes = new PhaseChanges($plan);
        $detail = $changes->detail($phase);
        $weights = $changes->weights($phase);
        $unit = $plan->weightUnit();
        $domain = PlanSnapshot::domainPhase($phase);
        $days = PhaseWarnings::daysToEvent($domain, $plan->today);
        $eventWeeks = $changes->eventWeeks($phase);

        return new PhaseShowOutput(
            id: (string) $phase->id,
            type: $phase->type->value,
            title: $phase->type->title(),
            dates_text: PlanPayload::phaseDates($phase),
            progress: $detail->progress === null ? null : round($detail->progress, 2),
            progress_text: $detail->progressText,
            length_text: $detail->weeks === null ? 'No end' : PlanWords::weeks($detail->weeks),
            rate_text: $plan->trainingOnly() ? null : PlanWords::rate((float) $phase->target_rate_percent_per_week),
            start_weight_text: $weights->startKg === null ? null : PlanWords::weight($weights->startKg, $unit),
            target_weight_text: $weights->targetKg === null ? null : PlanWords::weight($weights->targetKg, $unit),
            current_weight_text: $weights->currentKg === null ? null : PlanWords::weight($weights->currentKg, $unit),
            change_text: $weights->startKg === null || $weights->currentKg === null ? null : PlanWords::weightChange($weights->currentKg - $weights->startKg, $unit),
            event_date: $phase->event_date === null ? null : PlanSnapshot::day($phase->event_date)->format('Y-m-d'),
            event_text: $days === null ? null : PlanWords::eventCountdown($days),
            warnings: PhaseWarnings::runsPastEvent($domain) === null ? [] : [self::pastEvent($phase)],
            event_offer: $eventWeeks === null ? null : new PhaseEventOffer(
                text: 'End the phase in your event week, on '.PlanWords::day(self::lastDay($plan->dayOfWeek($plan->weeksOf($phase)->lower + $eventWeeks))).'?',
                weeks: $eventWeeks,
            ),
            can_end: $changes->canEnd($phase),
            lengths: array_map(fn (?int $weeks) => self::length($changes, $phase, $weeks, $detail->weeks), $changes->lengths($phase)),
            goals: array_map(fn ($type) => self::goal($plan, $changes->goal($phase, $type)), $changes->goalTypes($phase)),
        );
    }

    /** PH-006, PLAN-021: one length, with the fixes to pick from when it would end mid-mesocycle. */
    private static function length(PhaseChanges $changes, Phase $phase, ?int $weeks, ?int $current): PlanLengthChoice
    {
        $proposal = $changes->proposeLength($phase, $weeks);
        $fixes = [];
        if ($proposal->needsFix) {
            if ($proposal->moveEnd !== null) {
                $fixes[] = new PlanLengthFix('move_end_to_deload', 'End the phase after the deload on '.PlanWords::day(self::lastDay($proposal->moveEnd)));
            }
            if ($proposal->mesocycleChange !== null) {
                [$placed, $length] = $proposal->mesocycleChange;
                $verb = $length < (int) $placed->mesocycle->weeks ? 'Shorten' : 'Lengthen';
                $name = $placed->mesocycle->name ?? 'the mesocycle';
                $fixes[] = new PlanLengthFix('change_mesocycle_length', "{$verb} {$name} to ".PlanWords::weeks($length).' so its deload is the last week');
            }
            $fixes[] = new PlanLengthFix('keep_end', 'Keep '.PlanWords::day(self::lastDay($proposal->end)).' as the end');
        }

        return new PlanLengthChoice(
            weeks: $weeks,
            label: $weeks === null ? 'No end' : PlanWords::weeks($weeks),
            ends_text: $proposal->end === null ? 'No end date' : 'Ends '.PlanWords::day(self::lastDay($proposal->end)),
            is_selected: $weeks === $current,
            warnings: array_map(fn (PhaseWarning $w) => self::pastEvent($phase), $proposal->warnings),
            mesocycles: [],
            fixes: $fixes,
        );
    }

    /** PH-009 to PH-012: one type the phase may change to. */
    private static function goal(PlanSnapshot $plan, GoalPreview $goal): PhaseGoalChoice
    {
        return new PhaseGoalChoice(
            type: $goal->type->value,
            title: $goal->type->title(),
            from_text: 'From '.PlanWords::day($plan->dayOfWeek($goal->startWeek)),
            length_text: $goal->weeks === null ? 'No end' : PlanWords::weeks($goal->weeks),
            rate_text: $plan->trainingOnly() ? null : PlanWords::rate($goal->ratePercentPerWeek),
            refusal: $goal->refusal,
            warnings: array_map(fn (PhaseWarning $w) => new Status(PhaseChanges::warningText($w), Tone::Warning->value), $goal->warnings),
            training_lines: array_map(fn (string $line) => new PlanTextLine($line), $goal->trainingLines),
            progression_text: $goal->progressionFromWeek === null ? null
                : "{$goal->runningMesocycleName} follows {$goal->type->title()} progression from week {$goal->progressionFromWeek}.",
        );
    }

    /** PH-042. */
    private static function pastEvent(Phase $phase): Status
    {
        return new Status('This phase ends after your event on '.PlanWords::day(PlanSnapshot::day($phase->event_date)).'.', Tone::Warning->value);
    }

    /** The last day before an exclusive end. */
    private static function lastDay(DateTimeImmutable $end): DateTimeImmutable
    {
        return $end->modify('-1 day');
    }
}

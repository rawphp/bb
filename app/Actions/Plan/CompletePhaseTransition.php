<?php

namespace App\Actions\Plan;

use App\Capabilities\Support\FieldRefused;
use App\Domain\Model\PhaseType;
use App\Domain\Phases\NextPhaseOption;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PhaseTransitions;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PH-030 to PH-046: confirm the transition `plan_show` offers. What comes next starts where the ending phase ends:
 * one phase, or Gain first's three (PH-032). The first takes the client's ULID (CAP-019), the rest server ones.
 *
 * `template` (PH-036), `calorie_mode` and `step_kcal` (PH-044) are checked against what the option offers and then
 * dropped: no column holds them yet, and the Meso Builder, not this write, creates the next mesocycle.
 */
final class CompletePhaseTransition
{
    /**
     * `$replay` is the user's phase already saved under `$nextPhaseId`, if any: the transition ran before when it
     * follows the ending phase, and nothing is written again.
     *
     * @return list<string> the ending phase's id and the ids of the phases created
     */
    public function __invoke(
        User $user, string $phaseId, string $nextPhaseId, PhaseType $choice, bool $gainFirst, ?int $weeks,
        string $template, ?string $calorieMode, ?int $stepKcal, ?Phase $replay = null,
    ): array {
        return DB::transaction(function () use ($user, $phaseId, $nextPhaseId, $choice, $gainFirst, $weeks, $template, $calorieMode, $stepKcal, $replay): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet.');
            $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
            if ($replay !== null) {
                return self::replayed($plan, $phase, $replay);
            }
            $transitions = new PhaseTransitions($plan);
            if (! $transitions->due()?->is($phase)) {
                throw new DomainException('This phase has no transition to complete: it is not ending, or what comes next is already planned.');
            }
            $option = $transitions->find($choice, $gainFirst) ?? self::refuse('choice', 'That is not one of the next phases offered.');
            self::check($transitions, $option, $weeks, $template, $calorieMode, $stepKcal);
            $refusal = $transitions->refusal($option, $weeks);
            if ($refusal !== null) {
                throw new DomainException($refusal);
            }

            $ids = [$phaseId];
            foreach ($transitions->planned($option, $weeks) as $index => $planned) {
                $new = new Phase([
                    'type' => $planned['type'],
                    'start_date' => $plan->dayOfWeek($planned['start_week'])->format('Y-m-d'),
                    'end_date' => $plan->dayOfWeek($planned['start_week'] + $planned['weeks'])->format('Y-m-d'),
                    'target_rate_percent_per_week' => $transitions->rate($planned['type']),
                ]);
                $new->id = $index === 0 ? $nextPhaseId : (string) Str::ulid();
                $new->user_id = $user->id;
                $new->save();
                $ids[] = (string) $new->id;
            }

            return $ids;
        });
    }

    /** Each choice must be one the option offers (PH-031, PH-036, PH-044). */
    private static function check(PhaseTransitions $transitions, NextPhaseOption $option, ?int $weeks, string $template, ?string $calorieMode, ?int $stepKcal): void
    {
        $lengths = $transitions->lengths($option);
        if ($option->isGainFirst() ? $weeks !== null : ! in_array($weeks, $lengths, true)) {
            self::refuse('weeks', $option->isGainFirst()
                ? 'Gain first sets its own lengths, so send no weeks.'
                : 'Choose one of the lengths offered for '.$transitions->title($option).'.');
        }
        if (! in_array($template, $transitions->templates($option), true)) {
            self::refuse('template', 'That training template is not offered for '.$transitions->title($option).'.');
        }
        if (! $transitions->hasStepUp($option)) {
            if ($calorieMode !== null) {
                self::refuse('calorie_mode', 'Calories only step up for Maintenance after Fat loss, so send no calorie mode.');
            }
            if ($stepKcal !== null) {
                self::refuse('step_kcal', 'Calories only step up for Maintenance after Fat loss, so send no step.');
            }
        } elseif ($stepKcal !== null && ! in_array($stepKcal, PhaseTransitions::steps(), true)) {
            self::refuse('step_kcal', 'Step calories up by one of the steps offered: '.implode(' or ', PhaseTransitions::steps()).' kcal.');
        }
    }

    private static function refuse(string $field, string $message): never
    {
        throw new FieldRefused($field, $message);
    }

    /** @return list<string> */
    private static function replayed(PlanSnapshot $plan, Phase $ending, Phase $next): array
    {
        $later = $plan->laterPhases($ending);
        if (($later[0] ?? null)?->is($next) !== true || $ending->end_date === null || PlanSnapshot::day($ending->end_date) != PlanSnapshot::day($next->start_date)) {
            throw new DomainException('That new phase id is already used.');
        }

        return [(string) $ending->id, ...array_map(fn (Phase $p) => (string) $p->id, $later)];
    }
}

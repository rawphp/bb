<?php

namespace App\Actions\Settings;

use App\Domain\Model\PhaseType;
use App\Domain\Plan\PhaseSwitch;
use App\Domain\Plan\PhaseWrite;
use App\Domain\Safety\Eligibility;
use App\Domain\Safety\RecheckOutcome;
use App\Domain\Safety\RecheckOutcomeKind;
use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;
use App\Models\DayTarget;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Settings\ProfileScreening;
use App\Support\Settings\Recheck;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SAFE-017: "Re-check eligibility" runs the SAFE-001 screening again on the ticked items and the ones measured from
 * the profile, saves the items that applied, and acts on the outcome:
 *
 * - a nutrition user who now fails moves to training only at once: the phase switches from the first day of this
 *   plan week (PLAN-007), and the targets after today are removed; today's and earlier keep their logged meals;
 * - a training-only user who now passes, and a Fat loss or Mini-cut user now trying to conceive, go on to the
 *   nutrition steps or the goal choice in onboarding (ONB-005). Nothing else changes until those are done, so the
 *   user stays training only, or on the phase, meanwhile, and a re-check sends them there again;
 * - otherwise only the answers are saved.
 */
final class RecheckEligibility
{
    public const NO_PLAN = "Your plan isn't set up yet, so there is nothing to re-check.";

    public const NOTHING_CHANGED = 'Nothing changed. Your answers are saved.';

    public const TRAINING_ONLY_INTRO = 'Nutrition targets are turned off. You can still use every training feature, log weigh-ins and see your trend weight.';

    public const NUTRITION_STEPS = 'Your answers allow nutrition targets now. Answer a few nutrition questions to set them up.';

    public const FAT_LOSS_BLOCKED = "Fat loss isn't available while you're trying to conceive or in fertility treatment. Pick Maintenance or Muscle gain.";

    /** @param  list<ScreeningItem>  $ticked  the items the user ticked; the measured kinds among them are ignored */
    public function __invoke(User $user, array $ticked): Recheck
    {
        // The plan is read and changed under its row lock, so a write running at the same time cannot move it meanwhile.
        return DB::transaction(function () use ($user, $ticked): Recheck {
            $plan = PlanSnapshot::load($user, lock: true);
            $profile = $plan->profile ?? throw new DomainException(self::NO_PLAN);

            $now = Screening::eligibilityFor([
                ...ProfileScreening::withoutMeasuredKinds($ticked),
                ...ProfileScreening::measured($profile, $plan->today),
            ]);
            $phases = $plan->phases->map(fn (Phase $phase) => PlanSnapshot::domainPhase($phase))->values()->all();
            $current = PhaseSwitch::current($phases, $plan->grid, $plan->today);
            $outcome = Screening::recheck(self::previous($profile->training_only), $now, $current->type ?? PhaseType::Maintenance);

            $switches = $outcome->kind === RecheckOutcomeKind::SwitchToTrainingOnly;
            $profile->update([
                'screening' => array_map(fn (ScreeningItem $item) => $item->value, $outcome->reasons()),
                'training_only' => $switches ? true : $profile->training_only,
            ]);
            if (! $switches) {
                return new Recheck($outcome->kind, self::message($outcome), self::next($outcome->kind));
            }

            $writes = PhaseSwitch::toTrainingOnly($phases, $plan->grid, $plan->today, $plan->experience(), (string) Str::uuid());
            $removed = self::removeTargetsAfter($user, $plan);

            return new Recheck(
                $outcome->kind, self::message($outcome), 'done',
                phaseIds: array_map(fn (PhaseWrite $write) => self::save($user, $plan, $write), $writes),
                removedDates: $removed,
                weekDates: self::weekDates($plan, $removed),
            );
        });
    }

    /**
     * The mode the user is in. Screening::recheck reads only whether it has nutrition targets, which the profile's
     * training_only says; the saved items alone cannot, as a passing user stays training only until the steps are done.
     */
    private static function previous(bool $trainingOnly): Eligibility
    {
        return $trainingOnly ? Eligibility::trainingOnly([]) : Eligibility::allTargets();
    }

    private static function message(RecheckOutcome $outcome): string
    {
        return match ($outcome->kind) {
            RecheckOutcomeKind::Keep => self::NOTHING_CHANGED,
            RecheckOutcomeKind::SwitchToTrainingOnly => implode("\n", [
                self::TRAINING_ONLY_INTRO,
                ...array_map(fn (ScreeningItem $item) => '• '.$item->reason(), $outcome->reasons()),
            ]),
            RecheckOutcomeKind::RunNutritionSetup => self::NUTRITION_STEPS,
            RecheckOutcomeKind::LeaveFatLoss => self::FAT_LOSS_BLOCKED,
        };
    }

    /** @return 'done'|'onboarding' */
    private static function next(RecheckOutcomeKind $kind): string
    {
        return in_array($kind, [RecheckOutcomeKind::RunNutritionSetup, RecheckOutcomeKind::LeaveFatLoss], true) ? 'onboarding' : 'done';
    }

    private static function save(User $user, PlanSnapshot $plan, PhaseWrite $write): string
    {
        $phase = $write->exists ? $plan->findPhase($write->phase->id) : null;
        if ($phase === null) {
            $phase = new Phase;
            $phase->id = $write->phase->id;
            $phase->user_id = $user->id;
        }
        $phase->fill([
            'type' => $write->phase->type,
            'start_date' => $write->phase->start->format('Y-m-d'),
            'end_date' => $write->phase->end?->format('Y-m-d'),
            'target_rate_percent_per_week' => $write->phase->targetRatePercentPerWeek,
            'event_date' => $write->phase->eventDate?->format('Y-m-d'),
        ])->save();

        return (string) $phase->id;
    }

    /**
     * Training only builds no nutrition (SAFE-003), so the days after today lose their targets. Each is soft deleted
     * through its model, as every target delete is, so its meals stay for a restore.
     *
     * @return list<string> the days, Y-m-d
     */
    private static function removeTargetsAfter(User $user, PlanSnapshot $plan): array
    {
        $targets = DayTarget::query()->where('user_id', $user->id)
            ->where('date', '>', $plan->today->format('Y-m-d'))->orderBy('date')->get();
        $targets->each->delete();

        return $targets->map(fn (DayTarget $target) => PlanSnapshot::day($target->date)->format('Y-m-d'))->unique()->values()->all();
    }

    /**
     * Every day of the plan weeks holding `$dates`: the week strip shows each of its days' targets (NUT-021).
     *
     * @param  list<string>  $dates  Y-m-d
     * @return list<string>
     */
    private static function weekDates(PlanSnapshot $plan, array $dates): array
    {
        $weeks = array_unique(array_filter(array_map(
            fn (string $date) => $plan->grid->weekIndexContaining(PlanSnapshot::day($date)), $dates), fn (?int $week) => $week !== null));
        $days = [];
        foreach ($weeks as $week) {
            for ($offset = 0; $offset < $plan->grid->lengthInDays($week); $offset++) {
                $days[] = $plan->grid->start($week)->modify("+{$offset} days")->format('Y-m-d');
            }
        }

        return $days;
    }
}

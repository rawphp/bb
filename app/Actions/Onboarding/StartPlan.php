<?php

namespace App\Actions\Onboarding;

use App\Domain\Onboarding\OnboardingStart;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\OnboardingDraft;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighInScheduleEntry;
use App\Support\Mesocycles\TrainSnapshot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Support\CapabilityFailure;

/**
 * "Start my plan" (ONB-020): saves what the user's setup answers describe ({@see OnboardingStart}), in the rows
 * {@see StartRows} lays out, and deletes the draft, in one transaction. The profile, the plan, its first phase and
 * mesocycle, plan week 0 with its day targets and meals, and the weigh-in schedule are written through their models. A retry under the same Idempotency-Key gets
 * the first answer back (CAP-014); a start after the plan exists is refused.
 */
final class StartPlan
{
    public const ALREADY_STARTED = 'Your plan has already started.';

    /**
     * @throws DomainException when a step cannot continue (422)
     * @throws CapabilityFailure when the user already has a plan (409)
     */
    public function __invoke(User $user): OnboardingStart
    {
        return DB::transaction(function () use ($user): OnboardingStart {
            // The draft row is locked first, so a second start waits for this one and then finds the plan.
            $row = OnboardingDraft::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (Plan::query()->where('user_id', $user->id)->exists() || Profile::query()->where('user_id', $user->id)->exists()) {
                throw CapabilityFailure::conflict(self::ALREADY_STARTED);
            }

            $draft = ($row ?? OnboardingDraft::for($user))->toDraft();
            $newId = fn (): string => (string) Str::uuid7();
            $start = OnboardingStart::from(
                $draft,
                TrainSnapshot::day(CarbonImmutable::now()),
                OnboardingDraft::templates($draft),
                $newId,
            );

            $owned = ['user_id' => $user->id];
            (new Profile)->forceFill([...StartRows::profile($start), ...$owned])->save();
            (new Plan)->forceFill([...StartRows::plan($start), ...$owned])->save();
            (new Phase)->forceFill([...StartRows::phase($start), ...$owned])->save();
            (new Mesocycle)->forceFill([...StartRows::mesocycle($start), ...$owned])->save();
            (new PlanWeek)->forceFill([...StartRows::planWeek($start, $newId()), ...$owned])->save();
            foreach (StartRows::dayTargets($start, $newId) as $day) {
                (new DayTarget)->forceFill([...$day, ...$owned])->save();
            }
            foreach ($start->weighInSchedule as $entry) {
                (new WeighInScheduleEntry)->forceFill([...$entry, ...$owned])->save();
            }
            $row?->delete();

            return $start;
        });
    }
}

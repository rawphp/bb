<?php

namespace App\Models;

use App\Domain\Model\ReviewDayChange;
use App\Domain\Progress\PlanWeekDays;
use App\Models\Concerns\BelongsToUser;
use App\Support\Plan\PlanSnapshot;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The user's plan (PLAN-001): its start and the review day that starts every plan week (PLAN-008). One per user. */
#[Fillable(['start_date', 'review_weekday'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToUser, HasFactory;

    /** @return HasMany<PlanReviewDayChange, $this> PLAN-008: in date order */
    public function reviewDayChanges(): HasMany
    {
        return $this->hasMany(PlanReviewDayChange::class)->orderBy('effective_on');
    }

    /**
     * The stored changes as the domain takes them (1 = Sunday weekdays). Reads the relation, so eager-load it where a
     * query already loads the plan.
     *
     * @return list<ReviewDayChange>
     */
    public function domainReviewDayChanges(): array
    {
        return $this->reviewDayChanges->map(fn (PlanReviewDayChange $change) => new ReviewDayChange(
            PlanSnapshot::day($change->effective_on),
            PlanWeekDays::calendarWeekdayFromIso($change->review_weekday),
        ))->values()->all();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'review_weekday' => 'integer',
        ];
    }
}

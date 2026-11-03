<?php

namespace App\Models;

use Database\Factories\PlanReviewDayChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PLAN-008: from `effective_on`, the first day of the transition week, every plan week starts on `review_weekday` (ISO). */
#[Fillable(['effective_on', 'review_weekday'])]
class PlanReviewDayChange extends Model
{
    /** @use HasFactory<PlanReviewDayChangeFactory> */
    use HasFactory;

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_on' => 'date:Y-m-d',
            'review_weekday' => 'integer',
        ];
    }
}

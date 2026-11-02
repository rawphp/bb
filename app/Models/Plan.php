<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** The user's plan (PLAN-001): its start and the review day that starts every plan week (PLAN-008). One per user. */
#[Fillable(['start_date', 'review_weekday'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToUser, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'review_weekday' => 'integer',
        ];
    }
}

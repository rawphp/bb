<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\MealPlanningStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Still planning foods" or "Done planning foods" for one meal (DAY-004). */
#[Fillable(['user_id', 'meal_id', 'state'])]
class MealPlanningState extends Model
{
    /** @use HasFactory<MealPlanningStateFactory> */
    use BelongsToUser, HasFactory;

    protected $table = 'meal_planning_states';

    protected $primaryKey = 'meal_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<DayTargetMeal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(DayTargetMeal::class, 'meal_id');
    }
}

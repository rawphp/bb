<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\OffPlanEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Food eaten off-plan: an extra entry on the day with no meal slot (DAY-001). */
#[Fillable(['date', 'name', 'time', 'food_id', 'kcal', 'protein_g', 'fat_g', 'carbs_g'])]
class OffPlanEntry extends Model
{
    /** @use HasFactory<OffPlanEntryFactory> */
    use BelongsToUser, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'off_plan_entries';

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'kcal' => 'float',
            'protein_g' => 'float',
            'fat_g' => 'float',
            'carbs_g' => 'float',
        ];
    }
}

<?php

namespace App\Models;

use App\Domain\Model\MuscleGroup;
use Database\Factories\TemplateSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One exercise slot on a template day.
 *
 * @property int $day from 1
 * @property int $position
 * @property MuscleGroup $muscle_group
 * @property int $exercise_id
 * @property int $sets
 */
#[Fillable(['template_id', 'day', 'position', 'muscle_group', 'exercise_id', 'sets'])]
class TemplateSlot extends Model
{
    /** @use HasFactory<TemplateSlotFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'position' => 'integer',
            'muscle_group' => MuscleGroup::class,
            'sets' => 'integer',
        ];
    }
}

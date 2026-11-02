<?php

namespace App\Models;

use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A library training template (MESO-010). */
#[Fillable(['name', 'split', 'days_per_week', 'sex', 'phase_fit', 'priorities', 'day_labels'])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    /** @return HasMany<TemplateSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(TemplateSlot::class)->orderBy('day')->orderBy('position');
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'split' => Split::class,
            'days_per_week' => 'integer',
            'sex' => SexFit::class,
            'phase_fit' => AsEnumCollection::of(PhaseFit::class),
            'priorities' => 'array',
            'day_labels' => 'array',
        ];
    }
}

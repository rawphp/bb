<?php

namespace App\Models;

use App\Enums\MeasurementSource;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\WeighInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A body weight reading in kg, with an optional waist in cm (WT-021), typed in or from the Shortcuts bridge (WT-003). */
#[Fillable(['weight_kg', 'waist_cm', 'weighed_at', 'source'])]
class WeighIn extends Model
{
    /** @use HasFactory<WeighInFactory> */
    use BelongsToUser, HasFactory, HasUlids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'weight_kg' => 'float',
            'waist_cm' => 'float',
            'weighed_at' => 'datetime',
            'source' => MeasurementSource::class,
        ];
    }
}

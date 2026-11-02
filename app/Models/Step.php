<?php

namespace App\Models;

use App\Enums\MeasurementSource;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\StepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A day's step count, typed in or posted by the Shortcuts bridge (WT-020). One per user per day. */
#[Fillable(['date', 'count', 'source'])]
class Step extends Model
{
    /** @use HasFactory<StepFactory> */
    use BelongsToUser, HasFactory, HasUlids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'count' => 'integer',
            'source' => MeasurementSource::class,
        ];
    }
}

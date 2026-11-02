<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\OtherActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An activity outside the training plan (SCH-032, NUT-005). */
#[Fillable(['date', 'start_time', 'minutes', 'name', 'intensity'])]
class OtherActivity extends Model
{
    /** @use HasFactory<OtherActivityFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'minutes' => 'integer',
        ];
    }
}

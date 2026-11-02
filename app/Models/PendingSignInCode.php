<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PendingSignInCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one live emailed sign-in code for a user (ACC-001). Sending a new code replaces it.
 *
 * @property int $user_id
 * @property string $code_hash
 * @property int $failed_tries
 * @property CarbonImmutable $issued_at
 */
#[Fillable(['user_id', 'code_hash', 'failed_tries', 'issued_at'])]
class PendingSignInCode extends Model
{
    /** @use HasFactory<PendingSignInCodeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'failed_tries' => 'integer',
            'issued_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

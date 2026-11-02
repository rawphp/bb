<?php

namespace App\Models;

use App\Domain\Tokens\TokenScope;
use Database\Factories\PersonalAccessTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * A personal access token (CAP-021). Its ULID comes from the client (CAP-019),
 * and its abilities are capability names (CAP-023).
 *
 * @property string $id
 * @property string $name
 * @property TokenScope $scope
 * @property list<string> $abilities
 */
class PersonalAccessToken extends SanctumToken
{
    /** @use HasFactory<PersonalAccessTokenFactory> */
    use HasFactory, HasUlids;

    protected $table = 'personal_access_tokens';

    protected $fillable = ['id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'scope', 'expires_at'];

    /** @var array<string, string> */
    protected $casts = [
        'abilities' => 'json',
        'scope' => TokenScope::class,
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return Builder<self> the user's tokens */
    public static function ownedBy(User $user): Builder
    {
        return self::query()->whereMorphedTo('tokenable', $user);
    }
}

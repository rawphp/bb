<?php

namespace App\Models;

use App\Domain\Tokens\TokenScope;
use Database\Factories\PersonalAccessTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
#[Fillable(['id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'scope', 'expires_at'])]
class PersonalAccessToken extends SanctumToken
{
    /** @use HasFactory<PersonalAccessTokenFactory> */
    use HasFactory, HasUlids;

    /** @return array<string, string> Sanctum's casts, plus the scope */
    protected function casts(): array
    {
        return ['abilities' => 'json', 'scope' => TokenScope::class];
    }

    /**
     * The user's tokens, as this model. Sanctum's `tokens()` relation is typed to its own
     * model, so it loses the scope cast and the type checker's view of it.
     *
     * @return Builder<self>
     */
    public static function ownedBy(User $user): Builder
    {
        return self::query()->whereMorphedTo('tokenable', $user);
    }
}

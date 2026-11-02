<?php

namespace App\Actions\Tokens;

use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\Capabilities\Support\CapabilityFailure;

/** Makes a personal access token under the client's ULID (CAP-019, CAP-021). */
final class CreateToken
{
    /**
     * @param  list<string>  $picked  the custom scope's capability names
     * @param  list<string>  $reads  every read capability's name
     * @param  list<string>  $allowed  every capability a token may call
     * @return array{PersonalAccessToken, string} the token and its secret, shown once
     */
    public function __invoke(
        User $user,
        string $id,
        string $name,
        int $expiresInDays,
        TokenScope $scope,
        array $picked,
        array $reads,
        array $allowed,
    ): array {
        $existing = PersonalAccessToken::query()->find($id);
        if ($existing !== null) {
            if ((string) $existing->tokenable_id !== (string) $user->getKey()) {
                throw (new ModelNotFoundException)->setModel(PersonalAccessToken::class, [$id]);
            }

            throw CapabilityFailure::conflict('This token already exists.');
        }

        $secret = $user->generateTokenString();

        $token = new PersonalAccessToken([
            'id' => $id,
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => $scope->abilities($reads, $picked, $allowed),
            'scope' => $scope,
            'expires_at' => CarbonImmutable::now()->addDays($expiresInDays),
        ]);
        $token->tokenable()->associate($user)->save();

        return [$token, $id.'|'.$secret];
    }
}

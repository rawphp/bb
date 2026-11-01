<?php

namespace App\Capabilities\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes that create a row take its ULID from the client, so the offline
 * outbox can queue a create and then an edit of the same row (CAP-019).
 */
trait UsesClientUlid
{
    /**
     * The caller's row with this ULID, or null when the ULID is new.
     * A ULID another user owns is not found (404), never forbidden.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model  a model with a `user_id` column
     * @return TModel|null
     *
     * @throws ModelNotFoundException<TModel>
     */
    protected function ownedOrNew(string $model, string $ulid, Authenticatable $user): ?Model
    {
        if (! Str::isUlid($ulid)) {
            throw new InvalidArgumentException('That ID is not valid.');
        }

        $row = $model::query()->find($ulid);

        if ($row !== null && (string) $row->getAttribute('user_id') !== (string) $user->getAuthIdentifier()) {
            throw (new ModelNotFoundException)->setModel($model, [$ulid]);
        }

        return $row;
    }
}

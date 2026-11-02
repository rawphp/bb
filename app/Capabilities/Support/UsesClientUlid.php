<?php

namespace App\Capabilities\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Writes that create a row take its ULID from the client, so the offline
 * outbox can queue a create and then an edit of the same row (CAP-019).
 *
 * The input DTO declares the ID with Laravel's `ulid` rule, so a malformed ID
 * is a 422 that names the field before run() is reached.
 */
trait UsesClientUlid
{
    /**
     * The caller's row with this ULID, or null when the ULID is new.
     * A ULID another user owns is not found (404), never forbidden. A soft-deleted
     * row's ULID is spent: not found either, whoever owned it, so a retry of a
     * create whose row was since deleted cannot reuse its key.
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
        // Soft-deleted rows included: their ULIDs are spent.
        $row = $model::query()->withoutGlobalScope(SoftDeletingScope::class)->find($ulid);
        if ($row === null) {
            return null;
        }

        $spent = method_exists($row, 'trashed') && $row->trashed();
        if ($spent || (string) $row->getAttribute('user_id') !== (string) $user->getAuthIdentifier()) {
            throw (new ModelNotFoundException)->setModel($model, [$ulid]);
        }

        return $row;
    }
}

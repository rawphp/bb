<?php

namespace App\Capabilities\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A capability whose input names rows that already exist. The authorizer
 * checks each one belongs to the actor before anything runs (CAP-020 step 2).
 */
interface OwnsInput
{
    /**
     * Each input field holding a row ID, or a list of them, and a query for the rows the
     * user owns. A nested field is a dotted path, with `*` for each item of a list
     * (`entries.*.recipe_id`). A null or missing field, or an empty list, is not checked.
     *
     * @return array<string, Builder<*>|Relation<*, *, *>>
     */
    public function owned(User $user): array;
}

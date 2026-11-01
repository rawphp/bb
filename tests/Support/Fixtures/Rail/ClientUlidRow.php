<?php

namespace Tests\Support\Fixtures\Rail;

use Illuminate\Database\Eloquent\Model;

/** A user-owned row keyed by a client-made ULID, in a temporary table the test creates. */
final class ClientUlidRow extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'client_ulid_rows';

    protected $keyType = 'string';

    protected $guarded = [];
}

<?php

namespace Tests\Support\Fixtures\Rail;

use Illuminate\Database\Eloquent\Model;

/**
 * A user-owned row keyed by a client-made ULID, in a table the test creates on
 * its own in-memory SQLite connection. The package's stores hold the default
 * connection from before the test harness swapped it, and their first query
 * reconnects it, which would drop a temporary table on that connection.
 */
final class ClientUlidRow extends Model
{
    protected $connection = 'client_ulid_rows';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'client_ulid_rows';

    protected $keyType = 'string';

    protected $guarded = [];
}

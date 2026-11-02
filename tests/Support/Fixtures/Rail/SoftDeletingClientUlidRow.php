<?php

namespace Tests\Support\Fixtures\Rail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A row of the {@see ClientUlidRow} table that is soft-deleted, so a deleted row's ULID stays taken. */
final class SoftDeletingClientUlidRow extends Model
{
    use SoftDeletes;

    protected $connection = 'client_ulid_rows';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'client_ulid_rows';

    protected $keyType = 'string';

    protected $guarded = [];
}

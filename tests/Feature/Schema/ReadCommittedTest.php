<?php

use Illuminate\Support\Facades\DB;

// A write that waits for a lock must then read what the write before it committed (WO-010: a second Finish of a session
// finds it finished; FB-009: a second answer finds the first one's row). Under MySQL's default REPEATABLE READ a
// transaction keeps reading the view its first read took, so the connection reads committed rows instead.

it('WO-010 FB-009 a transaction reads a row another connection committed after its first read', function () {
    config(['database.connections.racer' => config('database.connections.'.config('database.default'))]);
    $racer = DB::connection('racer');
    $key = 'read-committed-'.bin2hex(random_bytes(6));

    try {
        $seen = DB::transaction(function () use ($racer, $key): bool {
            DB::table('cache')->count();
            $racer->table('cache')->insert(['key' => $key, 'value' => '1', 'expiration' => 0]);

            return DB::table('cache')->where('key', $key)->exists();
        });
    } finally {
        $racer->table('cache')->where('key', $key)->delete();
        DB::purge('racer');
    }

    expect($seen)->toBeTrue();
});

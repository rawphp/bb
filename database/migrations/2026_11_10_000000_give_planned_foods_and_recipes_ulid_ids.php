<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * CAP-019: planned foods and recipe servings are keyed by a ULID, so the outbox can queue a create and then an edit of
 * the same row. Each row keeps every other column and gets a fresh ULID. Nothing points at either key, and the undo
 * tokens that name these tables hold the old keys, so they go (an undo of one then answers 404, as an expired one does).
 * MySQL only.
 */
return new class extends Migration
{
    private const TABLES = ['planned_foods', 'planned_recipes'];

    public function up(): void
    {
        $this->forgetUndoTokens();

        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->char('ulid', 26)->nullable());

            foreach (DB::table($table)->orderBy('id')->pluck('id') as $id) {
                DB::table($table)->where('id', $id)->update(['ulid' => strtolower((string) Str::ulid())]);
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('id'));
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('ulid', 'id'));
            DB::statement("ALTER TABLE `{$table}` MODIFY `id` CHAR(26) NOT NULL, ADD PRIMARY KEY (`id`)");
        }
    }

    public function down(): void
    {
        // They hold ULID keys now.
        $this->forgetUndoTokens();

        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('number')->nullable());

            $number = 0;
            foreach (DB::table($table)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                DB::table($table)->where('id', $id)->update(['number' => ++$number]);
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('id'));
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('number', 'id'));
            DB::statement("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (`id`)");
        }
    }

    /** Deletes every undo token with a change to one of the tables, matched on the change's table. */
    private function forgetUndoTokens(): void
    {
        DB::table('undo_tokens')->orderBy('id')->chunkById(200, function ($tokens): void {
            $named = $tokens->filter(function ($token): bool {
                foreach (json_decode($token->changes, true) ?? [] as $change) {
                    if (in_array($change['table'] ?? null, self::TABLES, true)) {
                        return true;
                    }
                }

                return false;
            });

            DB::table('undo_tokens')->whereIn('id', $named->pluck('id')->all())->delete();
        });
    }
};

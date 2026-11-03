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
 *
 * Each table's key changes in one ALTER, which MySQL applies whole or not at all, so a run that stops leaves every table
 * with a working key. Every step checks the schema first, so running again finishes the job. A row inserted between the
 * fill and the swap has no ULID: the swap's NOT NULL refuses it, the table keeps its old key, and the next run fills it.
 * The old integer keys are not kept: nothing reads them, so down() numbers the rows again. MySQL only.
 */
return new class extends Migration
{
    private const TABLES = ['planned_foods', 'planned_recipes'];

    public function up(): void
    {
        $this->forgetUndoTokens();

        foreach (self::TABLES as $table) {
            if ($this->keyType($table) === 'char') {
                continue;
            }
            if (! Schema::hasColumn($table, 'ulid')) {
                Schema::table($table, fn (Blueprint $t) => $t->char('ulid', 26)->nullable());
            }
            foreach (DB::table($table)->whereNull('ulid')->orderBy('id')->pluck('id') as $id) {
                DB::table($table)->where('id', $id)->update(['ulid' => strtolower((string) Str::ulid())]);
            }
            $this->swapKey($table);
        }

        // Again after the swap, so a token an old process wrote while the tables changed goes too.
        $this->forgetUndoTokens();
    }

    /** The ULID column becomes the key, in one statement; it fails, changing nothing, while any row has no ULID. */
    public function swapKey(string $table): void
    {
        DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY, DROP COLUMN `id`, CHANGE `ulid` `id` CHAR(26) NOT NULL FIRST, ADD PRIMARY KEY (`id`)");
    }

    public function down(): void
    {
        // They hold ULID keys now.
        $this->forgetUndoTokens();

        foreach (self::TABLES as $table) {
            if ($this->keyType($table) !== 'char') {
                continue;
            }
            if (! Schema::hasColumn($table, 'number')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('number')->nullable());
            }
            $number = (int) DB::table($table)->max('number');
            foreach (DB::table($table)->whereNull('number')->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                DB::table($table)->where('id', $id)->update(['number' => ++$number]);
            }
            DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY, DROP COLUMN `id`, CHANGE `number` `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`id`)");
        }
    }

    /** The key column's type: `char` once swapped, `bigint` before. */
    private function keyType(string $table): string
    {
        return (string) collect(Schema::getColumns($table))->firstWhere('name', 'id')['type_name'];
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

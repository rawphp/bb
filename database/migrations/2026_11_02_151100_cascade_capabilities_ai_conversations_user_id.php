<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Rawphp\CapabilitiesAi\Models\TableNames;

/**
 * ACC-005: deleting a user deletes their Coach conversations, and with them the
 * messages, turns and proposals, which cascade from the conversation. The
 * package keeps the owner as a string; here it is the users key, with a
 * foreign key. An owner that names a user in another spelling ("007", " 7")
 * keeps the conversation, and the column change makes it that user's id. An
 * owner that is no user (deleted, or not an id) is set to null first and
 * logged: the rows stay, as the package's server-side conversations do, and
 * nothing is deleted. down() turns the column back into a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->settleOwners();

        Schema::table(TableNames::conversations(), function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /** Matched in PHP, so no SQL cast or collation decides what an id is. */
    public function settleOwners(): void
    {
        $conversations = fn () => DB::table(TableNames::conversations());
        $owners = $conversations()->whereNotNull('user_id')->distinct()->pluck('user_id')->map(fn ($owner) => (string) $owner);
        $ids = $owners->map(fn (string $owner) => ctype_digit(trim($owner)) ? (int) trim($owner) : null)->filter();
        $users = DB::table('users')->whereIn('id', $ids->unique()->values())->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($owners as $owner) {
            $id = ctype_digit(trim($owner)) ? (int) trim($owner) : null;
            if ($id !== null && in_array($id, $users, true)) {
                continue;
            }

            Log::warning('Coach conversations whose owner is not a user now have no owner', [
                'user_id' => $owner,
                'conversations' => $conversations()->where('user_id', $owner)->pluck('ulid')->all(),
            ]);
            $conversations()->where('user_id', $owner)->update(['user_id' => null]);
        }
    }

    public function down(): void
    {
        Schema::table(TableNames::conversations(), function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->string('user_id', 191)->nullable()->change();
        });
    }
};

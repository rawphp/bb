<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rawphp\CapabilitiesAi\Models\TableNames;

/**
 * ACC-005: deleting a user deletes their Coach conversations, and with them the
 * messages, turns and proposals, which cascade from the conversation. The
 * package keeps the owner as a string; here it is the users key, with a
 * foreign key. A conversation whose owner is not a user (deleted, or not an id
 * at all) belongs to nobody and goes first, with its messages and turns, so the
 * key can be added. down() keeps the column but cannot bring those rows back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deleteOwnerless();

        Schema::table(TableNames::conversations(), function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /** Conversations whose user_id names no user. Server-side ones (null) stay. */
    public function deleteOwnerless(): void
    {
        DB::table(TableNames::conversations())
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', DB::table('users')->selectRaw('CAST(id AS CHAR)'))
            ->delete();
    }

    public function down(): void
    {
        Schema::table(TableNames::conversations(), function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->string('user_id', 191)->nullable()->change();
        });
    }
};

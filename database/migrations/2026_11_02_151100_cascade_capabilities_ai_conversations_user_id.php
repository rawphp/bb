<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rawphp\CapabilitiesAi\Models\TableNames;

/**
 * ACC-005: deleting a user deletes their Coach conversations, and with them the
 * messages, turns and proposals, which cascade from the conversation. The
 * package keeps the owner as a string; here it is the users key, with a
 * foreign key. The agent tables are new in the same release, so no row can
 * hold an id that is not a user's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(TableNames::conversations(), function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(TableNames::conversations(), function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->string('user_id', 191)->nullable()->change();
        });
    }
};

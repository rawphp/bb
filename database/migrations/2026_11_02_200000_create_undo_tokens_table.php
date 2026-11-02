<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CAP-013: a reversible write's inverse, kept for 24 hours under its undo token.
        Schema::create('undo_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Each row the write changed: its table, key, and state before and after.
            $table->json('changes');
            // The write's read keys, returned again by undo_apply.
            $table->json('invalidates');
            $table->timestamp('expires_at');
            $table->timestamps();

            // The scheduled prune finds expired tokens by this alone; user_id has the foreign key's index.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undo_tokens');
    }
};

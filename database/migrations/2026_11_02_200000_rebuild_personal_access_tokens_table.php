<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal access tokens take a client-made ULID (CAP-019) and keep the scope the
 * user picked (CAP-021). Nothing minted a token before token_create, so the table
 * should be empty, and it is rebuilt rather than altered. A table that holds tokens
 * stops the migration, either way, instead of losing them: delete them first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refuseIfTokensExist();
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            // everything, read_only, health_bridge or custom.
            $table->string('scope', 20);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /** Back to Sanctum's table. A ULID cannot become a bigint, so it refuses while tokens exist. */
    public function down(): void
    {
        $this->refuseIfTokensExist();
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function refuseIfTokensExist(): void
    {
        if (Schema::hasTable('personal_access_tokens') && DB::table('personal_access_tokens')->exists()) {
            throw new RuntimeException('personal_access_tokens holds tokens, which a rebuild would lose. Delete them, then migrate again.');
        }
    }
};

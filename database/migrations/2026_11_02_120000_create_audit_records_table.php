<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every audited invoke (CAP-022): who, through which channel, when, the input
 * and the result. The spec requires both ends of its life: the scheduler prunes
 * it to 90 days (build plan, AUDIT; architecture.md, Scheduler) and, like every
 * user table, user_id cascades on account delete (ACC-005). The package's
 * capabilities_audit_outbox table is left as it is, with its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Null for a system actor, which no one's Activity shows. Cascade: ACC-005.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // capability.invoked | capability.failed | capability.output_invalid, or an approval event
            $table->string('event', 64);
            $table->string('capability', 64)->nullable();
            // ui | agent | mcp | cli | bridge
            $table->string('channel', 16);
            // Redacted by the package before it reaches the writer.
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_records');
    }
};

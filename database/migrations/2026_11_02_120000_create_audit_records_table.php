<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every audited invoke (CAP-022): who, through which channel, when, the input
 * and the result. Kept 90 days. It replaces the package's audit outbox as the
 * audit record, so deleting an account deletes its writes (ACC-005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Null for a system actor, which no one's Activity shows.
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

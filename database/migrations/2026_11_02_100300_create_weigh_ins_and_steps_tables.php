<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ULID keys, so the outbox can log a weigh-in or steps offline (CAP-019).
        Schema::create('weigh_ins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight_kg', 5, 2);
            // WT-021: optional, never changes a target.
            $table->decimal('waist_cm', 5, 1)->nullable();
            $table->timestamp('weighed_at');
            // WT-003: manual | shortcut
            $table->string('source');
            $table->timestamps();

            $table->index(['user_id', 'weighed_at']);
        });

        // WT-001, WT-007: weekdays with a time each, for the reminder.
        Schema::create('weigh_in_schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('weekdays');
            $table->string('time');
            $table->timestamps();
        });

        // WT-020: one count per day, typed (manual) or from the Shortcuts bridge (shortcut).
        Schema::create('steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('count');
            $table->string('source');
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steps');
        Schema::dropIfExists('weigh_in_schedule_entries');
        Schema::dropIfExists('weigh_ins');
    }
};

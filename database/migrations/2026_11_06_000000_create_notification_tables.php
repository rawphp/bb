<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NOTIF-007: a reminder type the user switched. A type with no row keeps its default.
        Schema::create('notification_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->boolean('on');
            $table->timestamps();

            $table->unique(['user_id', 'kind']);
        });

        // NOTIF-001 to NOTIF-005: each reminder sent and the time it was due, so the next pass does not send it
        // again. The unique key claims it, so two passes at once still send it once. Kept two days.
        Schema::create('sent_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_id', 64);
            $table->timestamp('due_at');
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'reminder_id', 'due_at']);
            $table->index('sent_at');
        });

        // NOTIF-004, NOTIF-005: a morning a pass judged a user's 09:00 reminders, sent or not, so the minutes after
        // 09:00 do not queue that user again. Kept two days.
        Schema::create('reminder_mornings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('judged_at');

            $table->unique(['user_id', 'date']);
            $table->index('judged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_mornings');
        Schema::dropIfExists('sent_reminders');
        Schema::dropIfExists('notification_prefs');
    }
};

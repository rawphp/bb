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

        // NOTIF-001 to NOTIF-005: each reminder sent, so the next pass does not send it again. Kept two days.
        Schema::create('sent_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_id', 64);
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'reminder_id']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_reminders');
        Schema::dropIfExists('notification_prefs');
    }
};

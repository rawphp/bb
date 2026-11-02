<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Library training templates (MESO-010), shared by every user.
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('split');
            $table->unsignedTinyInteger('days_per_week')->index();
            $table->string('sex')->index();
            $table->json('phase_fit');
            $table->json('priorities');
            $table->json('day_labels');
            $table->timestamps();
        });

        Schema::create('template_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day');
            $table->unsignedTinyInteger('position');
            $table->string('muscle_group');
            $table->foreignId('exercise_id')->constrained();
            $table->unsignedTinyInteger('sets');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_slots');
        Schema::dropIfExists('templates');
    }
};

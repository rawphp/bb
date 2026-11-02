<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // SET-004. Null body fat and GLP-1 on a training-only profile.
            $table->string('name');
            $table->string('sex');
            $table->date('birth_date');
            // SET-001: stored in metric, shown in the units below.
            $table->double('height_cm');
            $table->double('weight_kg');
            $table->string('body_fat_bucket')->nullable();
            $table->boolean('uses_glp1')->nullable();
            $table->string('experience');
            // SET-001 to SET-003
            $table->string('weight_unit');
            $table->string('height_unit');
            $table->string('appearance');
            $table->string('consumed_totals');
            $table->boolean('micro_plates')->default(false);
            // SET-005
            $table->json('eating_window');
            $table->unsignedTinyInteger('meal_count');
            $table->json('busy_periods');
            $table->json('training_days');
            $table->json('weigh_in_days');
            $table->json('step_plan');
            // SET-006
            $table->json('diet_filters');
            // The screening item values. Null until screening is answered.
            $table->json('screening')->nullable();
            $table->boolean('training_only')->default(false);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->unsignedTinyInteger('review_weekday');
            $table->timestamps();
        });

        Schema::create('phases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->double('target_rate_percent_per_week')->nullable();
            $table->date('event_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mesocycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('phase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('weeks')->nullable();
            $table->date('start_date')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_draft');
            $table->boolean('is_resensitization')->default(false);
            $table->json('days')->nullable();
            $table->json('muscle_priorities')->nullable();
            $table->json('earlier_weeks')->nullable();
            $table->json('later_weeks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_weeks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->boolean('is_deload');
            $table->boolean('is_diet_break');
            $table->boolean('diet_break_off')->default(false);
            $table->boolean('is_away');
            $table->string('away_training')->nullable();
            $table->string('away_calories')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'start_date']);
        });

        Schema::create('day_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('day_type');
            $table->double('kcal');
            $table->double('protein_g');
            $table->double('fat_g');
            $table->double('carbs_g');
            $table->json('meals');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_targets');
        Schema::dropIfExists('plan_weeks');
        Schema::dropIfExists('mesocycles');
        Schema::dropIfExists('phases');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('profiles');
    }
};

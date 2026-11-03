<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ONB-002: setup progress, the current step and every answer so far, one row per user. The first answer
        // creates it and onboarding_complete deletes it. What is worked out from the answers (the last day, the
        // target weight) is not stored. Weekdays are ISO (1 = Monday), times minutes after midnight.
        Schema::create('onboarding_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // 1 to 13, or 14 for the review (ONB-018).
            $table->unsignedTinyInteger('step')->default(1);
            // Step 1 (ONB-003, ONB-021)
            $table->string('goal')->nullable();
            $table->boolean('finished_diet_recently')->nullable();
            // Step 2 (ONB-004): stored in kg and cm, shown in the units below.
            $table->string('name', 100)->default('');
            $table->string('sex')->nullable();
            $table->date('birth_date')->nullable();
            $table->double('height_cm')->nullable();
            $table->double('weight_kg')->nullable();
            $table->string('weight_unit')->default('kg');
            $table->string('height_unit')->default('cm');
            // Step 3 (SAFE-001): the ticked item values.
            $table->json('screening');
            // Step 4 (ONB-006)
            $table->boolean('uses_glp1')->nullable();
            // Step 5 (ONB-007)
            $table->string('body_fat_bucket')->nullable();
            // Step 6 (ONB-008): the rate is signed as TBL-010, fat loss negative.
            $table->double('rate_percent_per_week')->nullable();
            $table->boolean('custom_rate')->default(false);
            $table->date('start_date')->nullable();
            $table->unsignedTinyInteger('weeks')->nullable();
            // Step 7 (ONB-010)
            $table->string('experience')->nullable();
            // Step 8 (ONB-011): [{weekday, start_minutes, length_minutes}]
            $table->json('training_days');
            // Step 9 (ONB-012)
            $table->string('training_plan')->nullable();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            // Step 10 (ONB-013): [{weekday, min, max}], empty until the user changes a range.
            $table->json('step_ranges');
            // Step 11 (ONB-014)
            $table->unsignedSmallInteger('eating_start_minutes')->default(7 * 60);
            $table->unsignedSmallInteger('eating_end_minutes')->default(20 * 60);
            $table->unsignedTinyInteger('meal_count')->default(5);
            // [{id, label, start_minutes, end_minutes, weekdays}]
            $table->json('busy_periods');
            // Step 12 (ONB-015, ONB-016): [{weekday, minutes}]; review_weekday null follows the weigh-ins.
            $table->json('weigh_ins');
            $table->unsignedTinyInteger('review_weekday')->nullable();
            // Step 13 (ONB-017)
            $table->boolean('chooses_own_calories')->default(false);
            $table->unsignedSmallInteger('own_daily_kcal')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_drafts');
    }
};

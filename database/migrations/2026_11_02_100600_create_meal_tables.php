<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Indexes the ids in day_targets.meals so what users plan and eat can point at a meal.
        Schema::create('day_target_meals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('day_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
        });

        // MEAL-001 to MEAL-008, FOOD-009
        Schema::create('planned_foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('meal_id')->constrained('day_target_meals')->cascadeOnDelete();
            $table->foreignUlid('food_id')->constrained('foods');
            $table->unsignedTinyInteger('position');
            $table->double('grams');
            $table->boolean('is_cooked');
            $table->boolean('is_locked');
            $table->string('unit');
            $table->timestamps();
            $table->index(['meal_id', 'position']);
        });

        // MEAL-040 to MEAL-047: one per meal. A ULID key, so the outbox can check a meal in offline (CAP-019).
        Schema::create('meal_check_ins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUuid('meal_id')->unique()->constrained('day_target_meals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('time')->nullable();
            $table->double('kcal');
            $table->double('protein_g');
            $table->double('fat_g');
            $table->double('carbs_g');
            $table->boolean('is_manual');
            $table->timestamps();
        });

        // DAY-004
        Schema::create('meal_planning_states', function (Blueprint $table) {
            $table->foreignUuid('meal_id')->primary()->constrained('day_target_meals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state');
            $table->timestamps();
        });

        // DAY-001. A ULID key, so the outbox can log one offline (CAP-019).
        Schema::create('off_plan_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name')->nullable();
            $table->string('time')->nullable();
            // The food it was logged from, so it counts as a recent (FOOD-023).
            $table->foreignUlid('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $table->double('kcal');
            $table->double('protein_g');
            $table->double('fat_g');
            $table->double('carbs_g');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'date']);
        });

        // MEAL-070, MEAL-071: a meal's foods saved for reuse. A ULID key (CAP-019).
        Schema::create('saved_meals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('saved_meal_foods', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('saved_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('food_id')->constrained('foods')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->double('grams');
            $table->boolean('is_cooked');
            $table->boolean('is_locked');
            $table->string('unit');
            $table->index(['saved_meal_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_meal_foods');
        Schema::dropIfExists('saved_meals');
        Schema::dropIfExists('off_plan_entries');
        Schema::dropIfExists('meal_planning_states');
        Schema::dropIfExists('meal_check_ins');
        Schema::dropIfExists('planned_foods');
        Schema::dropIfExists('day_target_meals');
    }
};

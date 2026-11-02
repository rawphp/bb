<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RCP-001, RCP-020: a user's recipe, or a library recipe when user_id is null. A ULID key (CAP-019).
        Schema::create('recipes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // The library seeder's key; null for users' recipes.
            $table->string('slug')->nullable()->unique();
            $table->string('name');
            $table->double('servings');
            $table->string('meal_type');
            $table->json('instructions')->nullable();
            // FOOD-027: true when every ingredient passes the filter. Same list as Food::DIETS.
            foreach (['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan'] as $diet) {
                $table->boolean($diet)->default(false);
            }
            $table->timestamps();
            // Soft, so a deleted recipe's planned servings can still be shown with their meal.
            $table->softDeletes();
            $table->index(['user_id', 'meal_type']);
        });

        // The whole batch; a serving is the batch divided by servings.
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('food_id')->constrained('foods');
            $table->unsignedSmallInteger('position');
            $table->double('grams');
            $table->boolean('is_cooked');
            $table->index(['recipe_id', 'position']);
        });

        // RCP-001, RCP-002: servings of a recipe in a meal, sharing positions with planned_foods.
        // `recipe` is the meal's copy of the recipe; RCP-021 refreshes it unless the meal is checked in.
        Schema::create('planned_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('meal_id')->constrained('day_target_meals')->cascadeOnDelete();
            $table->foreignUlid('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->double('servings');
            $table->boolean('is_locked');
            $table->json('recipe');
            $table->timestamps();
            $table->index(['meal_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_recipes');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};

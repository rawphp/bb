<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FOOD-060: a custom food is a foods row with an owner, so one food_id works in meals, recipes and lists.
        // A ULID key, so the outbox can create a custom food offline (CAP-019).
        Schema::create('foods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('brand', 255)->nullable();
            // Digits only, leading zeros stripped, so UPC-12 and EAN-13 match.
            $table->string('barcode', 32)->nullable()->index();
            $table->string('source', 16); // usda | off | curated | custom
            $table->string('source_id', 64);
            $table->string('kind', 16)->index(); // generic | packaged | restaurant | custom
            $table->boolean('curated')->default(false)->index();
            $table->string('curated_group', 32)->nullable()->index();

            // Per 100 g. Raw values for foods that change weight when cooked.
            $table->decimal('kcal', 7, 2);
            $table->decimal('protein', 7, 2);
            $table->decimal('fat', 7, 2);
            $table->decimal('carbs', 7, 2);
            $table->decimal('fiber', 7, 2);
            // Cooked grams per raw gram (chicken 0.75, white rice 3.0); null when raw and cooked do not differ.
            $table->decimal('cooked_ratio', 5, 3)->nullable();

            $table->boolean('dairy_free')->default(false);
            $table->boolean('gluten_free')->default(false);
            $table->boolean('grain_free')->default(false);
            $table->boolean('low_fodmap')->default(false);
            $table->boolean('vegetarian')->default(false); // ovo-lacto
            $table->boolean('paleo')->default(false);
            $table->boolean('vegan')->default(false);

            // Exactly what the user entered for a custom food's serving: {size, unit, grams, protein, fat, carbs, fiber}.
            $table->json('custom_serving')->nullable();
            $table->timestamps();
            // Soft, so the meals, recipes and saved meals that use a deleted custom food stay readable.
            $table->softDeletes();

            $table->unique(['source', 'source_id']);
            $table->index('name');
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['name', 'brand']);
            }
        });

        Schema::create('food_servings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('food_id')->constrained('foods')->cascadeOnDelete();
            $table->string('label');
            $table->decimal('grams', 8, 2);
        });

        // FOOD-023: the user's favorites.
        Schema::create('food_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('food_id')->constrained('foods')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'food_id']);
        });

        // FOOD-023: one row per food the user planned, checked in or logged off-plan, newest use last updated.
        Schema::create('food_recents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('food_id')->constrained('foods')->cascadeOnDelete();
            $table->timestamp('used_at');
            $table->unique(['user_id', 'food_id']);
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_recents');
        Schema::dropIfExists('food_favorites');
        Schema::dropIfExists('food_servings');
        Schema::dropIfExists('foods');
    }
};

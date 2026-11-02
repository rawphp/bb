<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SHOP-005: the lines a user ticked off and the date range they were ticked in. One row per user.
        Schema::create('shopping_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('keys');
            $table->timestamps();
        });

        // SHOP-005: items the user typed in. A ULID key, so the outbox can add one offline (CAP-019);
        // the app makes it when the user adds the item, so the list keeps them in that order.
        Schema::create('manual_shopping_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_shopping_items');
        Schema::dropIfExists('shopping_ticks');
    }
};

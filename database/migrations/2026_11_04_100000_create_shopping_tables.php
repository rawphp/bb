<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SHOP-005: the lines a user ticked off in a date range. One row per user and range, so a tick in
        // one range never touches another's; a tick drops the ranges that have ended.
        Schema::create('shopping_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('keys');
            $table->timestamps();
            $table->unique(['user_id', 'starts_on', 'ends_on']);
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

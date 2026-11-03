<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLAN-008: a change of review day, from the first day of its transition week. History stays: a row on or before
 * the user's today is never updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_review_day_changes', function (Blueprint $table) {
            $table->id();
            // Cascade: ACC-005, through the plan.
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            // The first day of the transition week.
            $table->date('effective_on');
            // ISO: 1 = Monday ... 7 = Sunday, as plans.review_weekday.
            $table->unsignedTinyInteger('review_weekday');
            $table->timestamps();

            $table->unique(['plan_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_review_day_changes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A library exercise when user_id is null, else the user's custom one.
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('muscle_group')->index();
            $table->string('equipment')->index();
            $table->string('load_type');
            $table->string('sfr_rating');
            $table->unsignedTinyInteger('rep_range_min');
            $table->unsignedTinyInteger('rep_range_max');
            $table->boolean('is_compound');
            $table->string('video_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};

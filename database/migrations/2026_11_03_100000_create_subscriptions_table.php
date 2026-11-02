<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per account that has started Checkout: its Stripe customer, and
     * the subscription as the last verified webhook described it (SUB-021).
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id')->charset('ascii')->unique();
            $table->string('stripe_subscription_id')->charset('ascii')->nullable();
            // Stripe's status: trialing, active, past_due, canceled, unpaid, ...
            $table->string('status')->nullable();
            $table->string('plan')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            // When Stripe made the last event applied here, so an older one arriving late is ignored.
            $table->timestamp('stripe_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

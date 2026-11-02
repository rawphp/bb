<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per account that has started Checkout: its Stripe customer, and
     * the subscription that decides its access, as Stripe last reported it (SUB-021).
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
            // The last Checkout page opened, closed before another opens so only one can be paid.
            $table->string('checkout_session_id')->charset('ascii')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

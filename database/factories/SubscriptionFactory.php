<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** A customer who started Checkout; set `status` for a subscription Stripe has reported. */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'stripe_customer_id' => 'cus_'.fake()->unique()->bothify('##??##??##??##'),
        ];
    }

    /** @return $this */
    public function configure(): static
    {
        return $this->afterMaking(function (Subscription $subscription) {
            if ($subscription->status !== null && $subscription->stripe_subscription_id === null) {
                $subscription->stripe_subscription_id = 'sub_'.fake()->unique()->bothify('##??##??##??##');
            }
        });
    }
}

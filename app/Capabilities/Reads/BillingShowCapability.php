<?php

namespace App\Capabilities\Reads;

use App\Billing\PlanPrices;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\BillingShowInput;
use App\Capabilities\Reads\Data\BillingShowOutput;
use App\Capabilities\Reads\Data\Settings\BillingPlan;
use App\Capabilities\Reads\Data\Settings\LapsedBanner;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\BillingOnly;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\Tone;
use App\Domain\Billing\Access;
use App\Domain\Billing\BillingRule;
use App\Domain\Billing\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class BillingShowCapability implements BillingOnly, HostCapability
{
    /** The plan the paywall selects first, when the instance sells it. */
    private const DEFAULT_PLAN = 'yearly';

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('billing_show')
            ->description('Show the user\'s subscription: its status, the plans on sale with their prices, whether the 7-day free trial is still available, and the lapsed banner once a subscription has ended. It changes nothing. Only with billing on.')
            ->cli('billing', 'show')
            ->input(BillingShowInput::class)
            ->output(BillingShowOutput::class)
            ->run(fn (BillingShowInput $input, CapabilityContext $context): BillingShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): BillingShowOutput
    {
        /** @var User $user */
        $user = $context->user();
        $subscription = Subscription::of($user);
        $access = Access::fromStripeStatus($subscription?->status);
        $trial = ! ($subscription?->hasSubscribed() ?? false);
        $plans = self::plans();
        $sold = array_keys(array_filter((array) config('billing.prices')));

        return new BillingShowOutput(
            status: self::status($access, $subscription),
            lapsed: $access === Access::Lapsed
                ? new LapsedBanner('Your subscription has ended. Your data is read-only.', 'Resubscribe')
                : null,
            plans: $plans,
            default_plan: in_array(self::DEFAULT_PLAN, $sold, true) ? self::DEFAULT_PLAN : (string) $sold[0],
            primary_label: $trial ? 'Start '.BillingRule::TRIAL_DAYS.'-day free trial' : 'Subscribe',
            terms_text: $trial
                ? 'Free for '.BillingRule::TRIAL_DAYS.' days, then the price above. Renews automatically unless you cancel before the trial ends. Manage or cancel in Settings.'
                : 'Renews automatically until you cancel. Manage or cancel in Settings.',
            can_manage: ! $trial,
        );
    }

    /**
     * The plans the instance sells, at Stripe's prices (SUB-002). A plan whose
     * price Stripe cannot give right now is left out, so the status, the banner
     * and the portal still show while Stripe is down.
     *
     * @return list<BillingPlan>
     */
    private static function plans(): array
    {
        $prices = app(PlanPrices::class);
        $plans = [];
        foreach (array_filter((array) config('billing.prices')) as $plan => $price) {
            $found = $prices->get((string) $price);
            if ($found === null) {
                continue;
            }
            $worded = new PlanPrice((string) $plan, $found['amount'], $found['currency'], $found['interval'], $found['interval_count']);
            $plans[] = new BillingPlan(plan: $worded->plan, title: $worded->title(), price_text: $worded->priceText(), detail: $worded->detail());
        }

        return $plans;
    }

    public static function status(Access $access, ?Subscription $subscription): Status
    {
        if ($subscription === null || $access === Access::None) {
            return new Status('No subscription', Tone::Neutral->value);
        }

        $plan = PlanPrice::TITLES[$subscription->plan ?? ''] ?? 'Subscribed';

        return match (true) {
            $access === Access::Lapsed => new Status('Ended', Tone::Warning->value),
            $access === Access::Trial => new Status('Free trial'.self::on(', ends', $subscription->trial_ends_at), Tone::Info->value),
            $subscription->status === 'past_due' => new Status('Payment failed. Update your card in Manage subscription.', Tone::Warning->value),
            $subscription->cancel_at_period_end => new Status($plan.self::on(', ends', $subscription->current_period_end), Tone::Warning->value),
            default => new Status($plan.self::on(', renews', $subscription->current_period_end), Tone::Good->value),
        };
    }

    private static function on(string $words, ?Carbon $date): string
    {
        return $date === null ? '' : "{$words} {$date->format('j F Y')}";
    }
}

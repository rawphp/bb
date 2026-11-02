<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApplyStripeSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe's webhook, the one way the server learns subscription state (SUB-021).
 * Only an event signed with the instance's webhook secret is read; anything
 * else answers 400 and changes nothing.
 */
final class StripeWebhookController
{
    /** The events that carry a subscription's state. */
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __invoke(Request $request, ApplyStripeSubscription $apply): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('billing.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return new Response('', 400);
        }

        if (in_array($event->type, self::SUBSCRIPTION_EVENTS, true)) {
            $apply($event->data->object->toArray(), $event->created);
        }

        // Every other event is acknowledged, so Stripe does not retry it.
        return new Response('', 200);
    }
}

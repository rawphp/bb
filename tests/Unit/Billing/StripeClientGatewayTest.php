<?php

use App\Billing\StripeClientGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Stripe\ApiRequestor;
use Stripe\Exception\AuthenticationException;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/** Stands in for Stripe's API: records each request and answers with the next body. */
function stripeApi(array $bodies): object
{
    $api = new class($bodies) implements ClientInterface
    {
        /** @var list<array{method: string, url: string, params: array, headers: array}> */
        public array $requests = [];

        public function __construct(private array $bodies) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => $headers];

            $body = array_shift($this->bodies);
            $code = $body['_status'] ?? 200;
            unset($body['_status']);

            return [json_encode($body), $code, []];
        }
    };
    ApiRequestor::setHttpClient($api);

    return $api;
}

beforeEach(function () {
    Cache::forget('billing:price:price_monthly:last');
    Cache::forget('billing:price:price_yearly');
    Cache::forget('billing:price:price_yearly:last');
    Cache::forget(StripeClientGateway::DOWN);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

function stripeClientGateway(): StripeClientGateway
{
    return new StripeClientGateway(new StripeClient(['api_key' => 'sk_test_bb', 'max_network_retries' => 0]));
}

it('SUB-021 creates the Stripe customer with the account\'s email and user id', function () {
    $api = stripeApi([['id' => 'cus_123', 'object' => 'customer']]);

    expect(stripeClientGateway()->createCustomer('sam@example.com', 'Sam Lifter', 42))->toBe('cus_123')
        ->and($api->requests[0]['url'])->toEndWith('/v1/customers')
        ->and($api->requests[0]['params'])->toBe(['email' => 'sam@example.com', 'name' => 'Sam Lifter', 'metadata' => ['user_id' => '42']]);
});

it('SUB-003 opens a subscription Checkout with the trial, promotion codes and the plan\'s price', function () {
    $api = stripeApi([['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']]);

    expect(stripeClientGateway()->checkout('cus_123', 'price_yearly', 7, 'https://bb.test'))->toBe(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])
        ->and($api->requests[0]['url'])->toEndWith('/v1/checkout/sessions')
        ->and($api->requests[0]['params'])->toBe([
            'mode' => 'subscription',
            'customer' => 'cus_123',
            'line_items' => [['price' => 'price_yearly', 'quantity' => 1]],
            // stripe-php sends a boolean as the form value 'true'.
            'allow_promotion_codes' => 'true',
            'success_url' => 'https://bb.test',
            'cancel_url' => 'https://bb.test',
            'expires_at' => $api->requests[0]['params']['expires_at'],
            'subscription_data' => ['trial_period_days' => 7],
        ])
        // A page left open closes within the hour, rather than Stripe's 24, so an old tab cannot sell a second subscription.
        ->and($api->requests[0]['params']['expires_at'])->toBeGreaterThanOrEqual(time() + 3500)->toBeLessThanOrEqual(time() + 3600);
});

it('SUB-009 sends no trial when the user has had one, and still takes promotion codes', function () {
    $api = stripeApi([['id' => 'cs_2', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_2']]);

    stripeClientGateway()->checkout('cus_123', 'price_monthly', null, 'https://bb.test');

    expect($api->requests[0]['params'])->not->toHaveKey('subscription_data')
        ->and($api->requests[0]['params']['allow_promotion_codes'])->toBe('true');
});

it('SUB-007 opens the customer portal for the customer', function () {
    $api = stripeApi([['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/bps_1']]);

    expect(stripeClientGateway()->portalUrl('cus_123', 'https://bb.test'))->toBe('https://billing.stripe.com/p/session/bps_1')
        ->and($api->requests[0]['url'])->toEndWith('/v1/billing_portal/sessions')
        ->and($api->requests[0]['params'])->toBe(['customer' => 'cus_123', 'return_url' => 'https://bb.test']);
});

it('SUB-002 reads a plan\'s price from Stripe', function () {
    stripeApi([['id' => 'price_yearly', 'object' => 'price', 'unit_amount' => 29999, 'currency' => 'usd', 'recurring' => ['interval' => 'year', 'interval_count' => 1]]]);

    expect(stripeClientGateway()->price('price_yearly'))->toBe(['amount' => 29999, 'currency' => 'usd', 'interval' => 'year', 'interval_count' => 1]);
});

it('SUB-021 lists every subscription of the customer, ended ones too', function () {
    $api = stripeApi([['object' => 'list', 'data' => [['id' => 'sub_1', 'object' => 'subscription', 'status' => 'canceled']], 'has_more' => false]]);

    expect(stripeClientGateway()->subscriptions('cus_123'))->toBe([['id' => 'sub_1', 'object' => 'subscription', 'status' => 'canceled']])
        ->and($api->requests[0]['url'])->toContain('/v1/subscriptions')
        ->and($api->requests[0]['params'])->toBe(['customer' => 'cus_123', 'status' => 'all', 'limit' => 100]);
});

it('SUB-021 reports a Stripe failure and tells the user only that payments are unavailable', function (Closure $call) {
    Exceptions::fake();
    stripeApi([['_status' => 401, 'error' => ['message' => 'Invalid API Key provided: sk_live_****abcd', 'type' => 'invalid_request_error']]]);

    expect(fn () => $call(stripeClientGateway()))
        ->toThrow(RuntimeException::class, 'Payments are unavailable right now. Try again shortly.');
    Exceptions::assertReported(AuthenticationException::class);
})->with([
    'customer' => fn () => fn (StripeClientGateway $stripe) => $stripe->createCustomer('sam@example.com', 'Sam', 1),
    'checkout' => fn () => fn (StripeClientGateway $stripe) => $stripe->checkout('cus_123', 'price_monthly', null, 'https://bb.test'),
    'close checkout' => fn () => fn (StripeClientGateway $stripe) => $stripe->closeCheckout('cs_1'),
    'portal' => fn () => fn (StripeClientGateway $stripe) => $stripe->portalUrl('cus_123', 'https://bb.test'),
    'subscriptions' => fn () => fn (StripeClientGateway $stripe) => $stripe->subscriptions('cus_123'),
]);

it('SUB-007 closes an open Checkout page, and says whether an earlier one was paid', function (string $status, bool $paid, int $calls) {
    $api = stripeApi([
        ['id' => 'cs_1', 'object' => 'checkout.session', 'status' => $status],
        ['id' => 'cs_1', 'object' => 'checkout.session', 'status' => 'expired'],
    ]);

    expect(stripeClientGateway()->closeCheckout('cs_1'))->toBe($paid)
        ->and($api->requests)->toHaveCount($calls);
    if ($calls === 2) {
        expect($api->requests[1]['url'])->toEndWith('/v1/checkout/sessions/cs_1/expire');
    }
})->with([
    'open' => ['open', false, 2],
    'paid' => ['complete', true, 1],
    'expired' => ['expired', false, 1],
]);

it('SUB-002 after Stripe fails, shows the last prices for a minute without asking Stripe again', function () {
    Exceptions::fake();
    Cache::forever('billing:price:price_monthly:last', ['amount' => 3499, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1]);
    Cache::forget('billing:price:price_monthly');
    $api = stripeApi([['_status' => 500, 'error' => ['message' => 'Stripe is down', 'type' => 'api_error']]]);

    expect(fn () => stripeClientGateway()->price('price_yearly'))->toThrow(RuntimeException::class);
    expect(stripeClientGateway()->price('price_monthly'))->toBe(['amount' => 3499, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1])
        ->and($api->requests)->toHaveCount(1)
        ->and(Cache::has(StripeClientGateway::DOWN))->toBeTrue();
});

it('SUB-002 shows the last price Stripe gave while Stripe cannot be reached', function () {
    Exceptions::fake();
    stripeApi([
        ['id' => 'price_yearly', 'object' => 'price', 'unit_amount' => 29999, 'currency' => 'usd', 'recurring' => ['interval' => 'year', 'interval_count' => 1]],
        ['_status' => 500, 'error' => ['message' => 'Stripe is down', 'type' => 'api_error']],
    ]);
    stripeClientGateway()->price('price_yearly');
    Cache::forget('billing:price:price_yearly');

    expect(stripeClientGateway()->price('price_yearly'))->toBe(['amount' => 29999, 'currency' => 'usd', 'interval' => 'year', 'interval_count' => 1]);
});

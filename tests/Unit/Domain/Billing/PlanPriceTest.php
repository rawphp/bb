<?php

use App\Domain\Billing\PlanPrice;

it('SUB-002 words a monthly price', function () {
    $price = new PlanPrice('monthly', 3499, 'usd', 'month', 1);

    expect($price->title())->toBe('Monthly')
        ->and($price->priceText())->toBe('$34.99 per month')
        ->and($price->detail())->toBeNull();
});

it('SUB-002 words a 6-month price with what it comes to a month', function () {
    $price = new PlanPrice('six_months', 17999, 'usd', 'month', 6);

    expect($price->title())->toBe('6 months')
        ->and($price->priceText())->toBe('$179.99 per 6 months')
        ->and($price->detail())->toBe('$30.00 a month');
});

it('SUB-002 words a yearly price with what it comes to a month', function () {
    $price = new PlanPrice('yearly', 29999, 'usd', 'year', 1);

    expect($price->title())->toBe('Yearly')
        ->and($price->priceText())->toBe('$299.99 per year')
        ->and($price->detail())->toBe('$25.00 a month');
});

it('SUB-002 shows the currency Stripe charges in', function () {
    expect((new PlanPrice('monthly', 4999, 'aud', 'month', 1))->priceText())->toBe('A$49.99 per month');
});

it('SUB-002 words a whole-unit currency without cents', function () {
    expect((new PlanPrice('monthly', 5000, 'jpy', 'month', 1))->priceText())->toBe('¥5,000 per month');
});

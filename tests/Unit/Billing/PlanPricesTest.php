<?php

use App\Billing\PlanPrices;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeStripe;

beforeEach(function () {
    Cache::forget(PlanPrices::DOWN);
    Cache::forget(PlanPrices::key('price_monthly'));
    $this->stripe = new FakeStripe;
});

it('SUB-002 keeps a price read from Stripe, and serves the kept copy after', function () {
    $prices = new PlanPrices($this->stripe);

    expect($prices->get('price_monthly'))->toMatchArray(['amount' => 3499]);
    $this->stripe->down = true;

    expect($prices->get('price_monthly'))->toMatchArray(['amount' => 3499]);
});

it('SUB-002 has no price while Stripe is down and none is kept, and stops asking Stripe for a minute', function () {
    $this->stripe->down = true;
    $prices = new PlanPrices($this->stripe);

    expect($prices->get('price_monthly'))->toBeNull()
        ->and(Cache::has(PlanPrices::DOWN))->toBeTrue();

    $this->stripe->down = false;

    expect($prices->get('price_monthly'))->toBeNull();
});

it('SUB-002 one request reads a missing price from Stripe; another arriving meanwhile does not wait on it', function () {
    $fetching = Cache::lock(PlanPrices::key('price_monthly').':fetch', 30);
    $fetching->get();

    try {
        expect((new PlanPrices($this->stripe))->get('price_monthly'))->toBeNull();
    } finally {
        $fetching->release();
    }
});

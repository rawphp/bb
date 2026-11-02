<?php

use App\Billing\PlanPrices;
use Carbon\CarbonImmutable;
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

it('SUB-002 reads a kept price again once it is older than the refresh interval, so prices follow Stripe without the scheduler', function () {
    $prices = new PlanPrices($this->stripe);
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    $prices->get('price_monthly');
    $this->stripe->prices['price_monthly']['amount'] = 3999;

    try {
        CarbonImmutable::setTestNow('2026-11-03 09:14:00');
        expect($prices->get('price_monthly'))->toMatchArray(['amount' => 3499]);

        CarbonImmutable::setTestNow('2026-11-03 09:16:00');
        expect($prices->get('price_monthly'))->toMatchArray(['amount' => 3999]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('SUB-002 keeps showing an old price while Stripe cannot give a new one', function () {
    $prices = new PlanPrices($this->stripe);
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    $prices->get('price_monthly');
    $this->stripe->down = true;

    try {
        CarbonImmutable::setTestNow('2026-11-03 10:00:00');
        expect($prices->get('price_monthly'))->toMatchArray(['amount' => 3499]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('SUB-002 while another request reads a stale price again, this one goes on with the old copy', function () {
    $prices = new PlanPrices($this->stripe);
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    $prices->get('price_monthly');
    $this->stripe->prices['price_monthly']['amount'] = 3999;
    CarbonImmutable::setTestNow('2026-11-03 10:00:00');
    $fetching = Cache::lock(PlanPrices::key('price_monthly').':fetch', 30);
    $fetching->get();

    try {
        expect($prices->get('price_monthly'))->toMatchArray(['amount' => 3499]);
    } finally {
        $fetching->release();
        CarbonImmutable::setTestNow();
    }
});

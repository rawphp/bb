<?php

use Tests\Support\DomainArch;

arch('app/Domain/Review is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Review')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

it('app/Domain/Review builds no dates: it only reads the ones it is given', function () {
    expect(DomainArch::filesThatBuildDates('Review'))->toBe([]);
});

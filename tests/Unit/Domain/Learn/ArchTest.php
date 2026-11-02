<?php

use Tests\Support\DomainArch;

arch('app/Domain/Learn is plain PHP: no Eloquent, facades, clock or config')
    ->expect('App\Domain\Learn')
    ->not->toUse(DomainArch::FORBIDDEN_USES);

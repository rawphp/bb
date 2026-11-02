<?php

use App\Enums\MesocycleRoute;
use App\Models\Mesocycle;

// MESO-002: the four ways a mesocycle is created, as the + menu shows them and a draft stores them.

it('MESO-002 the routes keep their wire values and titles, in menu order', function () {
    expect(array_map(fn (MesocycleRoute $route) => [$route->value, $route->title()], MesocycleRoute::cases()))->toBe([
        ['template', 'Template'],
        ['copy_previous', 'Copy previous mesocycle'],
        ['builder', 'Meso Builder'],
        ['scratch', 'Build from scratch'],
    ]);
});

it('MESO-007 a draft\'s route reads back as a route', function () {
    expect((new Mesocycle)->forceFill(['route' => 'copy_previous'])->route)->toBe(MesocycleRoute::CopyPrevious)
        ->and((new Mesocycle)->forceFill(['route' => null])->route)->toBeNull();
});

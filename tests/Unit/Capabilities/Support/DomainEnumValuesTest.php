<?php

use App\Capabilities\Support\DomainEnumValues;
use Illuminate\Support\Str;

it('keeps each DomainEnumValues list equal to the cases of the domain enum it is named after', function () {
    $constants = (new ReflectionClass(DomainEnumValues::class))->getConstants();

    foreach ($constants as $name => $values) {
        $studly = Str::studly(strtolower($name));
        $paths = glob(app_path("Domain/*/{$studly}.php")) ?: [];
        expect($paths)->toHaveCount(1, "{$name}: expected one app/Domain/*/{$studly}.php");

        $enum = 'App\\Domain\\'.basename(dirname($paths[0])).'\\'.$studly;
        expect(is_subclass_of($enum, BackedEnum::class))->toBeTrue("{$enum} is a backed enum")
            ->and($values)->toBe(array_column($enum::cases(), 'value'), "{$name} lists {$enum}'s values in case order");
    }

    expect($constants)->not->toBeEmpty();
});

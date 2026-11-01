<?php

// php scripts/schemas.php: write the schema snapshots for every capability an
// area's owners file names (tests/fixtures/schemas/<area>.owners.json) from its
// DTOs, app/Capabilities/*/Data/<StudlyName>Input.php and ...Output.php.
// A contract's snapshots come from here until its API issue registers it; from
// then on the CAP-032 test holds the snapshot to the live schema.

require __DIR__.'/../vendor/autoload.php';

use Tests\Support\ContractDtos;

$root = dirname(__DIR__);
$written = 0;

foreach (ContractDtos::ownedNames($root) as $name) {
    foreach (['input', 'output'] as $side) {
        $class = ContractDtos::find($root, $name, $side);
        if ($class === null) {
            continue;
        }

        file_put_contents("{$root}/tests/fixtures/schemas/{$name}.{$side}.json", ContractDtos::snapshot($class));
        $written++;
    }
}

echo "Wrote {$written} schema snapshot(s) to tests/fixtures/schemas/\n";

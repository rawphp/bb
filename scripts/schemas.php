<?php

// php scripts/schemas.php: write the schema snapshots for every capability an
// area's owners file names (tests/fixtures/schemas/<area>.owners.json) from its
// DTOs, app/Capabilities/*/Data/<StudlyName>Input.php and ...Output.php.
// It writes only a snapshot that is missing or no longer equal to its DTO's
// schema, so other areas' files keep their formatting. The CAP-032 tests in
// tests/Unit/Contracts/ScreenContractTest.php hold every snapshot to its DTO.

require __DIR__.'/../vendor/autoload.php';

use Rawphp\Capabilities\Support\SchemaSnapshot;
use Tests\Support\ContractDtos;

$root = dirname(__DIR__);
$written = 0;

foreach (ContractDtos::ownedNames($root) as $name) {
    foreach (['input', 'output'] as $side) {
        $class = ContractDtos::find($root, $name, $side);
        if ($class === null) {
            continue;
        }

        $path = "{$root}/tests/fixtures/schemas/{$name}.{$side}.json";
        if (is_file($path)) {
            $current = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (SchemaSnapshot::schemasEqual($current, $class::jsonSchema())) {
                continue;
            }
        }

        file_put_contents($path, ContractDtos::snapshot($class));
        $written++;
    }
}

echo "Wrote {$written} schema snapshot(s) to tests/fixtures/schemas/\n";

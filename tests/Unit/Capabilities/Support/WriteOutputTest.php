<?php

use App\Capabilities\Billing\Data\BillingCheckoutStartOutput;
use App\Capabilities\Billing\Data\BillingPortalOpenOutput;
use App\Capabilities\Settings\Data\EligibilityRecheckOutput;
use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Tokens\Data\CreatedToken;
use App\Capabilities\Tokens\Data\TokenCreateOutput;
use App\Domain\Safety\RecheckOutcomeKind;
use Tests\Support\Fixtures\Rail\LinkWriteOutput;

it('CAP-010 puts a write output\'s WriteResult fields at the top level, beside its own', function () {
    $output = new LinkWriteOutput(
        url: 'https://billing.example/session',
        note: null,
        result: new WriteResult(invalidates: ['billing_show{}'], toast: 'Opening checkout'),
    );

    expect($output->toArray())->toBe([
        'invalidates' => ['billing_show{}'],
        'toast' => 'Opening checkout',
        'undo' => null,
        'url' => 'https://billing.example/session',
        'note' => null,
    ]);
});

it('CAP-010 builds a write output\'s schema from WriteResult\'s and its own fields', function () {
    $schema = LinkWriteOutput::jsonSchema();
    $result = WriteResult::jsonSchema();

    expect($schema['properties'])->toBe($result['properties'] + [
        'url' => ['type' => 'string'],
        'note' => ['type' => ['string', 'null']],
    ])
        ->and($schema['required'])->toBe(['invalidates', 'url'])
        ->and($schema['additionalProperties'])->toBeFalse();
});

it('CAP-010 reads a write output back from its own wire shape', function () {
    $wire = ['invalidates' => ['billing_show{}'], 'toast' => null, 'undo' => '01JBX8Z6Q7M0V2K3N4P5R6S7T8', 'url' => 'https://billing.example/portal', 'note' => 'Back in a moment'];

    $output = LinkWriteOutput::fromArray($wire);

    expect($output->result->undo)->toBe('01JBX8Z6Q7M0V2K3N4P5R6S7T8')
        ->and($output->url)->toBe('https://billing.example/portal')
        ->and($output->toArray())->toBe($wire)
        ->and(fn () => LinkWriteOutput::fromArray(['result' => ['invalidates' => []]] + $wire))->toThrow(InvalidArgumentException::class);
});

it('CAP-010 sends every write output over HTTP in the flat shape its schema snapshot declares', function (string $name, WriteOutput $output) {
    // The HTTP response JSON-encodes the output object itself, and call.ts reads
    // `invalidates` at the top level (CAP-010), so the encoding must be the wire shape.
    $wire = json_decode((string) json_encode($output), true, flags: JSON_THROW_ON_ERROR);
    $snapshot = json_decode((string) file_get_contents(base_path("tests/fixtures/schemas/{$name}.output.json")), true, flags: JSON_THROW_ON_ERROR);

    expect($wire)->toBe($output->toArray())
        ->not->toHaveKey('result')
        ->and(array_keys($wire))->toEqualCanonicalizing(array_keys($snapshot['properties']))
        ->and($wire['invalidates'])->toBe(['billing_show{}']);
})->with([
    'billing_checkout_start' => ['billing_checkout_start', new BillingCheckoutStartOutput('https://checkout.example/s', new WriteResult(['billing_show{}']))],
    'billing_portal_open' => ['billing_portal_open', new BillingPortalOpenOutput('https://portal.example/s', new WriteResult(['billing_show{}']))],
    'eligibility_recheck' => ['eligibility_recheck', new EligibilityRecheckOutput(RecheckOutcomeKind::LeaveFatLoss->value, 'Fat loss is off.', 'done', new WriteResult(['billing_show{}']))],
    'token_create' => ['token_create', new TokenCreateOutput(new CreatedToken('01JBX8Z6Q7M0V2K3N4P5R6S7T8', 'Laptop', 'x|y', 'Everything', 'Expires 1 December 2026'), new WriteResult(['billing_show{}']))],
]);

it('CAP-010 lists every write output in the HTTP shape test', function () {
    $writeOutputs = [];
    foreach (glob(app_path('Capabilities/*/Data/*.php')) ?: [] as $file) {
        $class = 'App\\Capabilities\\'.basename(dirname($file, 2)).'\\Data\\'.basename($file, '.php');
        if (is_subclass_of($class, WriteOutput::class)) {
            $writeOutputs[] = $class;
        }
    }
    sort($writeOutputs);

    expect($writeOutputs)->toBe([BillingCheckoutStartOutput::class, BillingPortalOpenOutput::class, EligibilityRecheckOutput::class, TokenCreateOutput::class]);
});

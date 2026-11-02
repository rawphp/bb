<?php

use App\Capabilities\Support\WriteResult;
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

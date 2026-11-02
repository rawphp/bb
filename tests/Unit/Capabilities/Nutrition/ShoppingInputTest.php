<?php

use App\Capabilities\Nutrition\Data\ShoppingItemAddInput;
use App\Capabilities\Nutrition\Data\ShoppingItemCheckInput;
use App\Capabilities\Nutrition\Data\ShoppingShowInput;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;

// The shopping inputs through the package's own validation: the JSON schema, then the server
// rules, as a registered capability's invoke does.

/**
 * @param  class-string  $input
 * @return array<string, string> field => message, empty when the input passes
 */
function shoppingViolations(string $name, string $input, array $data): array
{
    $validator = new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator')));

    try {
        $validator->validate(new CapabilityDefinition(name: $name, input: $input), $data);
    } catch (SchemaValidationException $e) {
        return array_column($e->violations, 'message', 'field');
    }

    return [];
}

function shoppingCheck(array $changes = []): array
{
    return shoppingViolations('shopping_item_check', ShoppingItemCheckInput::class, [
        'from' => '2026-11-02', 'to' => '2026-11-08', 'key' => 'food:01JBX5F0000000000000000002', 'ticked' => true, ...$changes,
    ]);
}

it('SHOP-001 shows the default range, or the range asked for, one day or more', function (array $input) {
    expect(shoppingViolations('shopping_show', ShoppingShowInput::class, $input))->toBe([]);
})->with([
    'no range' => [[]],
    'a week' => [['from' => '2026-11-02', 'to' => '2026-11-08']],
    'one day' => [['from' => '2026-11-02', 'to' => '2026-11-02']],
]);

it('SHOP-001 refuses a range that ends before it starts, or has only one end', function (array $input, string $field) {
    expect(shoppingViolations('shopping_show', ShoppingShowInput::class, $input))->toHaveKey($field);
})->with([
    'ends before it starts' => [['from' => '2026-11-08', 'to' => '2026-11-02'], 'to'],
    'no end' => [['from' => '2026-11-02'], 'to'],
    'no start' => [['to' => '2026-11-08'], 'from'],
]);

it('SHOP-005 ticks a food line or a manual line', function (string $key) {
    expect(shoppingCheck(['key' => $key]))->toBe([]);
})->with(['food:01JBX5F0000000000000000002', 'manual:01jbx5h0000000000000000001']);

it('SHOP-005 refuses a tick of something that is not a line of the list', function (string $key) {
    expect(shoppingCheck(['key' => $key]))->toHaveKey('key');
})->with(['chicken', 'food:chicken', 'recipe:01JBX5F0000000000000000002', '']);

it('SHOP-005 refuses a tick in a range that ends before it starts', function () {
    expect(shoppingCheck(['from' => '2026-11-08', 'to' => '2026-11-02']))->toHaveKey('to');
});

it('SHOP-005 refuses a manual item with no words, or with an id that is not a ULID', function (array $input, string $field) {
    expect(shoppingViolations('shopping_item_add', ShoppingItemAddInput::class, ['id' => '01JBX5H0000000000000000001', 'text' => 'Soap', ...$input]))
        ->toHaveKey($field);
})->with([
    'blank text' => [['text' => "  \n "], 'text'],
    'not a ULID' => [['id' => 'soap'], 'id'],
]);

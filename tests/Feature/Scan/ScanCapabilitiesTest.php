<?php

use App\Models\Food;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Support\RecordingLlmClient;

const SCAN_JPEG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    // Curated, so the search finds them by LIKE: MySQL's full-text index does not see a test's uncommitted rows.
    $this->rice = Food::factory()->create(['name' => 'White rice', 'curated' => true, 'kcal' => 130, 'protein' => 2.7, 'fat' => 0.3, 'carbs' => 28]);
    $this->bowl = Food::factory()->create(['name' => 'Burrito bowl', 'curated' => true, 'brand' => 'Chipotle', 'kind' => 'restaurant', 'kcal' => 150, 'protein' => 9, 'fat' => 5, 'carbs' => 17]);
});

/** The plate the AI sees: rice, a burrito bowl, and a sauce no food matches. */
function aiPlate(): RecordingLlmClient
{
    return RecordingLlmClient::answering(['items' => [
        ['name' => 'Cooked rice', 'search' => 'white rice', 'grams' => 200],
        ['name' => 'Burrito bowl', 'search' => 'burrito bowl', 'grams' => 100],
        ['name' => 'Mystery sauce', 'search' => 'zzzz', 'grams' => 30],
    ]])->install();
}

/** @param  array<string, mixed>  $input */
function scan(string $name, array $input): array
{
    $data = test()->actingAs(test()->user)
        ->postJson("/capabilities/{$name}", $input)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json('data');

    $schema = json_decode((string) file_get_contents(base_path("tests/fixtures/schemas/{$name}.output.json")), true);
    expect((new JsonSchemaValidator)->validate($schema, $data))->toBe([], "{$name} breaks its output schema");

    return $data;
}

it('FOOD-043 a photo becomes an editable list of foods matched to database foods, at one times', function () {
    $llm = aiPlate();

    $data = scan('scan_photo', ['image' => SCAN_JPEG]);

    expect($llm->userContent()[0]['type'])->toBe('image')
        ->and($data['rows'])->toBe([
            ['name' => 'Cooked rice', 'grams' => 200, 'base_grams' => 200, 'grams_text' => '200 g', 'food_id' => $this->rice->id, 'match_text' => 'White rice', 'detail' => null, 'kcal_text' => '260 kcal'],
            ['name' => 'Burrito bowl', 'grams' => 100, 'base_grams' => 100, 'grams_text' => '100 g', 'food_id' => $this->bowl->id, 'match_text' => 'Burrito bowl', 'detail' => 'Chipotle', 'kcal_text' => '150 kcal'],
            ['name' => 'Mystery sauce', 'grams' => 30, 'base_grams' => 30, 'grams_text' => '30 g', 'food_id' => null, 'match_text' => 'No match', 'detail' => null, 'kcal_text' => null],
        ])
        ->and($data['portion'])->toEqual(1)
        ->and($data['portion_text'])->toBe('1×')
        ->and($data['portion_down'])->toEqual(0.75)
        ->and($data['portion_up'])->toEqual(1.25)
        ->and($data['totals'])->toBe(['kcal' => '410 kcal', 'protein' => 'P 14', 'fat' => 'F 6', 'carbs' => 'C 73'])
        ->and($data['unmatched_note'])->toBe('1 food has no match and won\'t be added or saved.')
        ->and($data['matched'])->toBe([['food_id' => $this->rice->id, 'grams' => 200], ['food_id' => $this->bowl->id, 'grams' => 100]])
        ->and($data['saved_meal_name'])->toBe('Scanned meal')
        ->and($data['empty_text'])->toBeNull();
});

it('FOOD-043 a photo that finds nothing says so', function () {
    RecordingLlmClient::answering(['items' => []])->install();

    $data = scan('scan_photo', ['image' => SCAN_JPEG]);

    expect($data['rows'])->toBe([])
        ->and($data['matched'])->toBe([])
        ->and($data['empty_text'])->toBe('No foods found. Try another photo or describe the meal.');
});

it('FOOD-043 FOOD-044 FOOD-042 a scan saves nothing', function () {
    RecordingLlmClient::answering(['items' => [['name' => 'Cooked rice', 'search' => 'white rice', 'grams' => 200]]])->install();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1 && ! str_contains($query->sql, 'cache')) {
            $writes[] = $query->sql;
        }
    });

    scan('scan_photo', ['image' => SCAN_JPEG]);
    scan('scan_describe', ['description' => 'rice and a burrito bowl']);
    scan('scan_adjust', ['rows' => [['name' => 'Rice', 'grams' => 100, 'food_id' => $this->rice->id]], 'from_portion' => 1, 'portion' => 2]);
    RecordingLlmClient::answering([
        'name' => 'Oats', 'brand' => null, 'serving' => null,
        'per_100g' => ['kcal' => 380, 'protein' => 13, 'fat' => 7, 'carbs' => 60, 'fiber' => 10],
    ])->install();
    scan('scan_label', ['image' => SCAN_JPEG]);

    expect($writes)->toBe([]);
});

it('FOOD-044 a typed description is trimmed and sent for the same kind of list, and names the saved meal', function () {
    $llm = aiPlate();

    $data = scan('scan_describe', ['description' => "  chicken burrito bowl with rice \n"]);

    expect($llm->userContent())->toBe([['type' => 'text', 'text' => 'chicken burrito bowl with rice']])
        ->and(array_column($data['rows'], 'match_text'))->toBe(['White rice', 'Burrito bowl', 'No match'])
        ->and($data['saved_meal_name'])->toBe('chicken burrito bowl with rice');
});

it('FOOD-006 FOOD-043 rice on the plate is cooked weight: its calories count cooked, and it is added by its raw weight', function () {
    $this->rice->update(['kcal' => 365, 'protein' => 7.1, 'fat' => 0.7, 'carbs' => 80, 'cooked_ratio' => 3.0]);
    RecordingLlmClient::answering(['items' => [['name' => 'Cooked rice', 'search' => 'white rice', 'grams' => 200]]])->install();

    $data = scan('scan_photo', ['image' => SCAN_JPEG]);

    expect($data['rows'][0]['grams_text'])->toBe('200 g')
        ->and($data['rows'][0]['kcal_text'])->toBe('243 kcal')
        ->and($data['matched'])->toEqual([['food_id' => $this->rice->id, 'grams' => 66.7]]);
});

it('FOOD-044 a blank description is refused without a call', function () {
    $llm = aiPlate();

    $this->actingAs($this->user)
        ->postJson('/capabilities/scan_describe', ['description' => "  \n "])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.message', 'Describe the meal in 1 to 1000 characters.');

    expect($llm->calls)->toBe([]);
});

it('FOOD-045 the portion scales the whole scanned meal, and the rows come back at the new grams', function () {
    $data = scan('scan_adjust', [
        'rows' => [
            ['name' => 'Cooked rice', 'grams' => 200, 'food_id' => $this->rice->id],
            ['name' => 'Burrito bowl', 'grams' => 100, 'food_id' => $this->bowl->id],
            ['name' => 'Mystery sauce', 'grams' => 30, 'food_id' => null],
        ],
        'from_portion' => 1,
        'portion' => 1.25,
        'saved_meal_name' => 'Burrito night',
    ]);

    expect(array_column($data['rows'], 'grams'))->toEqual([250, 125, 37.5])
        ->and(array_column($data['rows'], 'grams_text'))->toBe(['250 g', '125 g', '37.5 g'])
        ->and($data['portion_text'])->toBe('1.25×')
        ->and($data['portion_down'])->toEqual(1)
        ->and($data['portion_up'])->toEqual(1.5)
        ->and($data['matched'])->toEqual([['food_id' => $this->rice->id, 'grams' => 250], ['food_id' => $this->bowl->id, 'grams' => 125]])
        ->and($data['totals']['kcal'])->toBe('513 kcal')
        ->and($data['saved_meal_name'])->toBe('Burrito night');
});

it('FOOD-045 FOOD-046 a draft sent back as returned steps the portion without drifting and keeps its name', function () {
    RecordingLlmClient::answering(['items' => [['name' => 'Cooked rice', 'search' => 'white rice', 'grams' => 33]]])->install();
    $draft = scan('scan_describe', ['description' => 'rice']);

    foreach ([1.25, 1.5, 1.75] as $portion) {
        $draft = scan('scan_adjust', [
            'rows' => array_map(fn (array $row) => ['name' => $row['name'], 'grams' => $row['grams'], 'base_grams' => $row['base_grams'], 'food_id' => $row['food_id']], $draft['rows']),
            'from_portion' => $draft['portion'],
            'portion' => $portion,
            'saved_meal_name' => $draft['saved_meal_name'],
        ]);
    }

    expect($draft['rows'][0]['grams'])->toEqual(57.8)
        ->and($draft['saved_meal_name'])->toBe('rice');
});

it('FOOD-045 totals over a thousand calories are written with a thousands separator', function () {
    $data = scan('scan_adjust', ['rows' => [['name' => 'Rice', 'grams' => 200, 'food_id' => $this->rice->id]], 'from_portion' => 1, 'portion' => 4]);

    expect($data['totals']['kcal'])->toBe('1,040 kcal');
});

it('FOOD-043 a new match, a typed amount and a removed row are what the user sends back', function () {
    $basmati = Food::factory()->create(['name' => 'Basmati rice', 'kcal' => 350, 'protein' => 8, 'fat' => 1, 'carbs' => 78]);

    $data = scan('scan_adjust', [
        'rows' => [['name' => 'Cooked rice', 'grams' => 100, 'food_id' => $basmati->id]],
        'from_portion' => 1.25,
        'portion' => 1.25,
    ]);

    expect($data['rows'])->toBe([
        ['name' => 'Cooked rice', 'grams' => 100, 'base_grams' => 80, 'grams_text' => '100 g', 'food_id' => $basmati->id, 'match_text' => 'Basmati rice', 'detail' => null, 'kcal_text' => '350 kcal'],
    ])->and($data['unmatched_note'])->toBeNull()
        ->and($data['saved_meal_name'])->toBe('Scanned meal');
});

it('FOOD-042 FOOD-060 a label becomes a custom food draft per serving, carrying the barcode that found nothing, with the calories its macros give', function () {
    $llm = RecordingLlmClient::answering([
        'name' => 'Oat bar', 'brand' => 'Barco', 'serving' => ['label' => '1 bar', 'grams' => 40],
        'per_100g' => ['kcal' => 400, 'protein' => 10, 'fat' => 12, 'carbs' => 60, 'fiber' => 8],
    ])->install();

    $data = scan('scan_label', ['image' => SCAN_JPEG, 'barcode' => '5012345678900']);

    expect($llm->userContent()[0]['type'])->toBe('image')
        ->and($data)->toEqual([
            'name' => 'Oat bar', 'brand' => 'Barco', 'barcode' => '5012345678900',
            'serving_size' => 40, 'serving_unit' => 'g', 'serving_grams' => 40,
            'protein' => 4, 'fat' => 4.8, 'carbs' => 24, 'fiber' => 3.2, 'kcal_text' => '155 kcal',
        ]);
});

it('FOOD-042 a label with no serving is per 100 g, with no barcode', function () {
    RecordingLlmClient::answering([
        'name' => 'Oats', 'brand' => null, 'serving' => null,
        'per_100g' => ['kcal' => 380, 'protein' => 13, 'fat' => 7, 'carbs' => 60, 'fiber' => 10],
    ])->install();

    $data = scan('scan_label', ['image' => SCAN_JPEG]);

    expect($data['serving_grams'])->toEqual(100)
        ->and($data['barcode'])->toBeNull()
        ->and($data['brand'])->toBeNull()
        ->and($data['kcal_text'])->toBe('355 kcal');
});

it('FOOD-042 FOOD-043 FOOD-044 a scan with no AI key is refused in plain words', function (string $name, array $input) {
    config(['capabilities-ai.llm.driver' => 'anthropic', 'capabilities-ai.llm.anthropic.api_key' => null]);

    $this->actingAs($this->user)
        ->postJson("/capabilities/{$name}", $input)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'AI isn\'t set up on this server.');
})->with([
    'photo' => ['scan_photo', ['image' => SCAN_JPEG]],
    'description' => ['scan_describe', ['description' => 'toast']],
    'label' => ['scan_label', ['image' => SCAN_JPEG]],
]);

it('CAP-020 each scan refuses a signed-out caller', function (string $name, array $input) {
    $this->postJson("/capabilities/{$name}", $input)->assertUnauthorized();
})->with([
    'photo' => ['scan_photo', ['image' => SCAN_JPEG]],
    'description' => ['scan_describe', ['description' => 'toast']],
    'adjust' => ['scan_adjust', ['rows' => [], 'from_portion' => 1, 'portion' => 1]],
    'label' => ['scan_label', ['image' => SCAN_JPEG]],
]);

it('CAP-012 each scan names the one bad field', function (string $name, array $input, string $field) {
    $this->actingAs($this->user)
        ->postJson("/capabilities/{$name}", $input)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.violations.0.field', $field);
})->with([
    'photo that is not a JPEG' => ['scan_photo', ['image' => 'data:image/png;base64,AAAA'], 'image'],
    'description too long' => ['scan_describe', ['description' => str_repeat('x', 1001)], 'description'],
    'portion over four times' => ['scan_adjust', ['rows' => [], 'from_portion' => 1, 'portion' => 4.25], 'portion'],
    'label barcode with letters' => ['scan_label', ['image' => SCAN_JPEG, 'barcode' => '12ab'], 'barcode'],
]);

it('CAP-020 scan_adjust answers 404 for another user\'s custom food', function () {
    $theirs = Food::factory()->custom(User::factory()->create())->create();

    $this->actingAs($this->user)
        ->postJson('/capabilities/scan_adjust', ['rows' => [['name' => 'Bar', 'grams' => 50, 'food_id' => $theirs->id]], 'from_portion' => 1, 'portion' => 1])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('CAP-020 scan_adjust matches the user\'s own custom food', function () {
    $mine = Food::factory()->custom($this->user)->create(['name' => 'My bar']);

    $data = scan('scan_adjust', ['rows' => [['name' => 'Bar', 'grams' => 50, 'food_id' => $mine->id]], 'from_portion' => 1, 'portion' => 1]);

    expect($data['rows'][0]['match_text'])->toBe('My bar');
});

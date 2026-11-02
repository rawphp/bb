<?php

use App\Ai\AiFailed;
use App\Ai\FoodAi;
use App\Ai\LabelReading;
use Tests\Support\RecordingLlmClient;

const LABEL_JPEG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

it('FOOD-042 reads a label into a custom-food draft per 100 g, rounded to 2 places', function () {
    $llm = RecordingLlmClient::answering([
        'name' => 'Granola', 'brand' => null,
        'serving' => ['label' => '1 serving', 'grams' => 45.004],
        'per_100g' => ['kcal' => 450.123, 'protein' => 9.5, 'fat' => 18, 'carbs' => 60, 'fiber' => 7],
    ])->install();

    $label = app(FoodAi::class)->label(LABEL_JPEG);

    expect($label)->toEqual(new LabelReading(
        name: 'Granola', brand: null, servingLabel: '1 serving', servingGrams: 45.0,
        kcal: 450.12, protein: 9.5, fat: 18.0, carbs: 60.0, fiber: 7.0,
    ));
    $image = $llm->userContent()[0];
    expect($image)->toBe(['type' => 'image', 'source' => [
        'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => substr(LABEL_JPEG, strlen('data:image/jpeg;base64,')),
    ]])
        ->and($llm->userContent()[1])->toBe(['type' => 'text', 'text' => 'Read this nutrition label.'])
        ->and($llm->system())->toStartWith(file_get_contents(resource_path('prompts/label.md')));
});

it('FOOD-042 allows a label with no serving weight and a brand', function () {
    RecordingLlmClient::answering([
        'name' => 'Oats', 'brand' => 'Acme', 'serving' => null,
        'per_100g' => ['kcal' => 370, 'protein' => 13, 'fat' => 7, 'carbs' => 60, 'fiber' => 10],
    ])->install();

    $label = app(FoodAi::class)->label(LABEL_JPEG);

    expect($label->brand)->toBe('Acme')
        ->and($label->servingLabel)->toBeNull()
        ->and($label->servingGrams)->toBeNull()
        ->and($label->kcal)->toBe(370.0);
});

it('FOOD-042 reads a label that lists no fat, fibre or protein', function () {
    RecordingLlmClient::answering([
        'name' => 'Cola', 'brand' => null, 'serving' => ['label' => '1 can', 'grams' => 0.5],
        'per_100g' => ['kcal' => 42, 'protein' => 0, 'fat' => 0, 'carbs' => 10.6, 'fiber' => 0.4],
    ])->install();

    $label = app(FoodAi::class)->label(LABEL_JPEG);

    expect([$label->protein, $label->fat, $label->fiber, $label->servingGrams])->toBe([0.0, 0.0, 0.4, 0.5]);
});

it('FOOD-042 refuses a label reading with a negative amount', function (array $reading) {
    RecordingLlmClient::answering($reading + [
        'name' => 'Oats', 'brand' => null, 'serving' => null,
        'per_100g' => ['kcal' => 370, 'protein' => 13, 'fat' => 7, 'carbs' => 60, 'fiber' => 10],
    ])->install();

    expect(fn () => app(FoodAi::class)->label(LABEL_JPEG))->toThrow(AiFailed::class);
})->with([
    'per 100 g' => [['per_100g' => ['kcal' => 370, 'protein' => -1, 'fat' => 7, 'carbs' => 60, 'fiber' => 10]]],
    'the serving weight' => [['serving' => ['label' => '1 cup', 'grams' => -40]]],
]);

it('FOOD-042 FOOD-043 says the photo could not be read, and asks nothing, when it is not a base64 image data URL', function (string $image) {
    $llm = RecordingLlmClient::answering([])->install();

    expect(fn () => app(FoodAi::class)->label($image))->toThrow(AiFailed::class, 'That photo couldn\'t be read. Try another.');
    expect($llm->calls)->toBe([]);
})->with([
    'no data URL' => ['/9j/4AAQSkZJRg=='],
    'not an image' => ['data:application/pdf;base64,JVBERi0='],
    'not base64' => ['data:image/jpeg;base64,not base64!'],
    'empty' => ['data:image/jpeg;base64,'],
]);

<?php

use App\Domain\Foods\CustomFoodServing;
use App\Models\Food;

it('FOOD-061 reads a custom food\'s serving as it was entered', function () {
    $food = new Food(['custom_serving' => ['size' => 1, 'unit' => 'cup', 'grams' => 95, 'protein' => 10, 'fat' => 16, 'carbs' => 58, 'fiber' => 6]]);

    expect($food->customServing())->toEqual(new CustomFoodServing(1, 'cup', 95, 10, 16, 58, 6));
});

it('FOOD-061 shows a custom food kept without its serving as 100 g of it', function () {
    $food = new Food(['protein' => 20, 'fat' => 10, 'carbs' => 40, 'fiber' => 3.33]);

    expect($food->customServing())->toEqual(new CustomFoodServing(100, 'g', 100, 20, 10, 40, 3.33))
        ->and($food->customServing()->detail())->toBe('100 g · 330 kcal');
});

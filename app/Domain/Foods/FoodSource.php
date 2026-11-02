<?php

namespace App\Domain\Foods;

/** Where a food row came from: the `source` column. Imported datasets carry the credit their licence asks for. */
enum FoodSource: string
{
    case Usda = 'usda';
    case OpenFoodFacts = 'off';
    case Curated = 'curated';
    case Custom = 'custom';

    /** The datasets `foods:import` reads. */
    public static function importable(string $value): ?self
    {
        $source = self::tryFrom($value);

        return in_array($source, [self::Usda, self::OpenFoodFacts], true) ? $source : null;
    }

    /**
     * The credit shown in the food detail and in Settings → About. Open Food Facts is under the
     * Open Database License, which requires it; USDA FoodData Central is public domain and credited by courtesy.
     */
    public function attribution(): ?string
    {
        return match ($this) {
            self::OpenFoodFacts => 'Food data from Open Food Facts (openfoodfacts.org), available under the Open Database License (ODbL).',
            self::Usda => 'Food data from USDA FoodData Central (fdc.nal.usda.gov), in the public domain.',
            self::Curated, self::Custom => null,
        };
    }

    /**
     * Every dataset credit, for Settings → About.
     *
     * @return list<string>
     */
    public static function attributions(): array
    {
        return array_values(array_filter(array_map(fn (self $source) => $source->attribution(), self::cases())));
    }
}

<?php

namespace App\Domain\Scan;

use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;

/**
 * FOOD-043 to FOOD-046: the scanned meal at one portion. Nothing is saved: Add to meal and Save as
 * saved meal send `matched()` to their own capabilities.
 *
 * Scanned grams are what is on the plate, so a food that changes weight when cooked (FOOD-006) is
 * weighed cooked: its calories count cooked, and `matched()` holds its raw weight, the basis a meal
 * and a saved meal store.
 */
final readonly class ScannedMeal
{
    public const DEFAULT_NAME = 'Scanned meal';

    /** The saved meal name's limit (MEAL-070). */
    public const NAME_LIMIT = 255;

    public const EMPTY_TEXT = 'No foods found. Try another photo or describe the meal.';

    /**
     * @param  list<ScanItem>  $items
     */
    private function __construct(
        public array $items,
        public float $portion,
        public string $savedMealName,
    ) {}

    /**
     * FOOD-043, FOOD-044: the AI's estimate, at one times.
     *
     * @param  list<ScanItem>  $items
     * @param  ?string  $description  the typed description, which starts the saved meal's name (FOOD-046); null for a photo
     */
    public static function scanned(array $items, ?string $description): self
    {
        return new self($items, 1.0, self::name($description));
    }

    /**
     * FOOD-043, FOOD-045: the list as the user left it (each row's base from ScanPortion::base), at `portion`.
     *
     * @param  list<ScanItem>  $items
     */
    public static function adjusted(array $items, float $portion, ?string $savedMealName): self
    {
        return new self($items, $portion, self::name($savedMealName));
    }

    /** The item's grams as shown at this portion. */
    public function grams(ScanItem $item): float
    {
        return ScanPortion::grams($item->baseGrams * $this->portion);
    }

    /** FOOD-046: trimmed and cut to the name limit, or "Scanned meal" when there is none. */
    private static function name(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? self::DEFAULT_NAME : mb_substr($name, 0, self::NAME_LIMIT);
    }

    /** The item's calories by the meal's own rule; null with no match. */
    public function kcal(ScanItem $item): ?float
    {
        return $item->food === null ? null : $this->served($item->food, $this->grams($item))->macros()->kcal;
    }

    /**
     * FOOD-045, FOOD-046: the matched rows, one per food, in the order each food first appears, by raw weight:
     * rows matched to the same food add up. Totals, Add to meal and the saved meal all read this, so they agree.
     *
     * @return list<MealFood>
     */
    public function matched(): array
    {
        $raw = [];
        $foods = [];
        foreach ($this->items as $item) {
            if ($item->food === null) {
                continue;
            }
            $id = $item->food->id;
            $raw[$id] = ($raw[$id] ?? 0.0) + $this->served($item->food, $this->grams($item))->rawGrams();
            $foods[$id] = $item->food;
        }

        return array_values(array_map(
            fn (string $id): MealFood => new MealFood($id, $foods[$id], ScanPortion::grams($raw[$id])),
            array_map('strval', array_keys($foods)),
        ));
    }

    /** The food at `grams` as served: cooked weight when it has a cooked ratio. */
    private function served(Food $food, float $grams): MealFood
    {
        return new MealFood($food->id, $food, $grams, Weighing::Cooked);
    }

    /** FOOD-045: the matched foods' calories and macros. */
    public function totals(): MealTarget
    {
        return (new MealFit)->totals($this->matched());
    }

    /** FOOD-043: rows with no match are not added or saved, and the screen says so. */
    public function unmatchedNote(): ?string
    {
        $count = count(array_filter($this->items, fn (ScanItem $item): bool => $item->food === null));

        return match ($count) {
            0 => null,
            1 => '1 food has no match and won\'t be added or saved.',
            default => "{$count} foods have no match and won't be added or saved.",
        };
    }

    /** FOOD-043, FOOD-044: what the screen says when the list is empty. */
    public function emptyText(): ?string
    {
        return $this->items === [] ? self::EMPTY_TEXT : null;
    }
}

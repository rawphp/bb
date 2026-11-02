<?php

namespace App\Domain\Shopping;

/** SHOP-001 to SHOP-005: the shopping list as the screen shows it, and as text to share. */
final class ShoppingScreen
{
    public const string EMPTY_TEXT = 'No foods planned for these days.';

    /**
     * SHOP-003, SHOP-005: the planned groups in store order, each line ticked when its key is. The manual
     * items close the Other group, which they make when nothing planned is in it.
     *
     * @param  list<ShoppingGroup>  $groups
     * @param  list<ManualItem>  $manual
     * @param  list<string>  $ticked
     * @return list<ListSection>
     */
    public static function sections(array $groups, array $manual, array $ticked): array
    {
        $ticked = array_flip($ticked);
        $sections = [];
        foreach ($groups as $group) {
            $sections[] = new ListSection($group->category, array_map(function (ShoppingItem $item) use ($ticked): ListLine {
                $key = TickedRange::foodKey($item->food->id);

                return new ListLine($key, $item->food->name, self::amountText($item->grams), isset($ticked[$key]), false);
            }, $group->items));
        }

        if ($manual === []) {
            return $sections;
        }

        $lines = array_map(function (ManualItem $item) use ($ticked): ListLine {
            $key = TickedRange::manualKey($item->id);

            return new ListLine($key, $item->text, null, isset($ticked[$key]), true);
        }, $manual);
        $last = array_key_last($sections);
        if ($last !== null && $sections[$last]->category === StoreCategory::Other) {
            $sections[$last] = new ListSection(StoreCategory::Other, [...$sections[$last]->lines, ...$lines]);
        } else {
            $sections[] = new ListSection(StoreCategory::Other, $lines);
        }

        return $sections;
    }

    /** SHOP-003: purchase amounts are whole grams ("1100 g"). */
    public static function amountText(float $grams): string
    {
        return ((int) round($grams)).' g';
    }

    /**
     * SHOP-005: a heading per group and a line per item with its grams. Ticked items, and the groups
     * they leave empty, are not in it.
     *
     * @param  list<ListSection>  $sections
     */
    public static function shareText(array $sections): string
    {
        $blocks = [];
        foreach ($sections as $section) {
            $lines = [];
            foreach ($section->lines as $line) {
                if (! $line->isTicked) {
                    $lines[] = $line->amountText === null ? $line->name : "{$line->name} — {$line->amountText}";
                }
            }
            if ($lines !== []) {
                $blocks[] = implode("\n", [$section->category->title(), ...$lines]);
            }
        }

        return implode("\n\n", $blocks);
    }

    /** SHOP-001: "Nov 2 – Nov 8"; one day is just that day. */
    public static function rangeText(DateRange $range): string
    {
        $first = $range->lower->format('M j');
        $last = $range->upper->format('M j');

        return $range->lower->format('Y-m-d') === $range->upper->format('Y-m-d') ? $first : "{$first} – {$last}";
    }

    /** @param  list<ListSection>  $sections */
    public static function emptyText(array $sections): ?string
    {
        return $sections === [] ? self::EMPTY_TEXT : null;
    }
}

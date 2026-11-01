<?php

namespace App\Domain\Shopping;

/** SHOP-003: store categories, in the order the list shows them. */
enum StoreCategory: string
{
    case Protein = 'protein';
    case Produce = 'produce';
    case GrainsAndBread = 'grainsAndBread';
    case Dairy = 'dairy';
    case Pantry = 'pantry';
    case Frozen = 'frozen';
    case Other = 'other';

    /**
     * The first rule with a matching phrase wins, so a specific phrase sits above the word inside it:
     * "peanut butter" and "butter bean" before "butter", "frozen" before "peas", "egg noodle" before "egg".
     */
    private const array KEYWORD_LISTS = [
        ['frozen', 'frozen|ice cream|gelato|sorbet'],
        ['pantry', 'butter bean|peanut butter|almond butter|nut butter|cashew butter|coconut milk|coconut cream|stock|broth|bouillon|'
            .'sauce|ketchup|mustard|mayonnaise|mayo|dressing|salsa|tomato paste|vinegar|canned|tinned|black pepper'],
        ['grainsAndBread', 'egg noodle|corn tortilla'],
        ['dairy', 'milk|yogurt|yoghurt|cheese|butter|cream|cottage|kefir|ghee|skyr|quark|mozzarella|cheddar|parmesan|feta|'
            .'ricotta|halloumi|paneer|creme fraiche'],
        ['protein', 'chicken|beef|steak|turkey|pork|bacon|ham|sausage|lamb|veal|duck|salmon|tuna|cod|tilapia|trout|sardine|'
            .'mackerel|fish|shrimp|prawn|crab|lobster|scallop|mince|egg|tofu|tempeh|seitan|jerky'],
        ['produce', 'apple|banana|orange|lemon|lime|berry|berries|blueberry|blueberries|strawberry|strawberries|raspberry|'
            .'raspberries|blackberry|blackberries|grape|mango|pineapple|melon|watermelon|peach|pear|plum|cherry|'
            .'cherries|kiwi|avocado|tomato|tomatoes|potato|potatoes|carrot|broccoli|cauliflower|spinach|kale|lettuce|'
            .'cabbage|cucumber|zucchini|courgette|pepper|onion|garlic|ginger|mushroom|celery|asparagus|pumpkin|squash|'
            .'beetroot|green bean|pea|corn|eggplant|aubergine|leek|radish|basil|parsley|coriander|cilantro|mint|salad|'
            .'vegetable|veggie|veg|fruit|bok choy|edamame|capsicum|rocket|arugula'],
        ['grainsAndBread', 'rice|oat|oatmeal|bread|bagel|pasta|spaghetti|noodle|tortilla|wrap|quinoa|couscous|cereal|granola|muesli|'
            .'flour|bun|pita|naan|cracker|barley|bulgur|cornflake|wheat|toast|crumpet|muffin'],
        ['pantry', 'oil|honey|jam|sugar|salt|spice|cumin|paprika|turmeric|cinnamon|oregano|bean|lentil|chickpea|nut|almond|'
            .'peanut|walnut|cashew|pecan|pistachio|seed|chia|flax|tahini|syrup|cocoa|chocolate|baking|yeast|coffee|tea'],
    ];

    /** The name the list shows for the group. */
    public function title(): string
    {
        return match ($this) {
            self::Protein => 'Protein',
            self::Produce => 'Produce',
            self::GrainsAndBread => 'Grains and bread',
            self::Dairy => 'Dairy',
            self::Pantry => 'Pantry',
            self::Frozen => 'Frozen',
            self::Other => 'Other',
        };
    }

    /**
     * SHOP-003: the store category of a food. Name keywords decide first, so "Greek yogurt" is dairy although it is
     * curated as protein; then the curated group (FOOD-003, the server's raw value such as `protein_recommended`);
     * anything left is other.
     */
    public static function of(string $name, ?string $curatedGroup): self
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (self::KEYWORD_LISTS as [$category, $list]) {
            foreach (explode('|', $list) as $phrase) {
                if (self::contains($words, explode(' ', $phrase))) {
                    return self::from($category);
                }
            }
        }

        return match ($curatedGroup) {
            'protein_recommended', 'protein_sparingly', 'protein_before_bed' => self::Protein,
            'veggies' => self::Produce,
            'carbs_recommended', 'carbs_sparingly' => self::GrainsAndBread,
            'fat_recommended', 'fat_sparingly' => self::Pantry,
            default => self::Other,
        };
    }

    /**
     * Whole words only ("peanuts" is not "pea"), and a plural matches its singular ("eggs", "tomatoes").
     *
     * @param  list<string>  $words
     * @param  list<string>  $phrase
     */
    private static function contains(array $words, array $phrase): bool
    {
        for ($start = 0; $start <= count($words) - count($phrase); $start++) {
            $matches = true;
            foreach ($phrase as $offset => $keyword) {
                $word = $words[$start + $offset];
                if ($word !== $keyword && $word !== $keyword.'s' && $word !== $keyword.'es') {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}

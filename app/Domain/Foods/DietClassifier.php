<?php

namespace App\Domain\Foods;

use App\Domain\Model\DietFilter;

/**
 * Derives the seven diet flags (FOOD-027) from name, category and ingredient text.
 * Conservative: a flag is true only when no keyword rules it out, and a packaged
 * product with no ingredient data and no tags gets every flag false.
 *
 * Flags come from lowercased name + category + ingredients:
 *  - vegetarian (ovo-lacto): false on meat, fish, poultry, seafood, cured meats, gelatin,
 *    rennet, carmine and collagen words.
 *  - vegan: also false on dairy, egg, honey.
 *  - dairy_free: false on milk, cheese (and named cheeses), butter, cream, whey, casein,
 *    yogurt, curd, lactose and similar (not on nut/plant milks and butters, bean curd,
 *    cream of tartar).
 *  - A category that names a substitute (plant based milk, milk substitutes, meat analogues,
 *    vegetarian meats, imitation...) is left out of the text: it says what the food replaces.
 *  - gluten_free: false on wheat, barley, rye, spelt, malt, semolina, couscous,
 *    seitan, bread, pasta, flour, breaded, batter, crumbs, gluten, triticale, durum, graham,
 *    compounds such as oatmeal, breadcrumb, flatbread and malted, and oats, which are usually
 *    cross-contaminated (not rice, almond, coconut, corn... flour). Keywords match whole words
 *    with an -s or -es plural, so other plurals and compounds are listed.
 *  - grain_free: false on the gluten words plus rice, corn, cornmeal, polenta, grits, masa,
 *    oat, quinoa, millet, sorghum, buckwheat, amaranth, teff, cereal, noodle, granola.
 *  - paleo: false on dairy, grains, legumes (bean, lentil, chickpea, pea, peanut,
 *    soy, tofu), and sugar, syrup, sweeteners, sweetened, cola, soda, juice cocktail.
 *  - low_fodmap: false on a high-FODMAP list (garlic, onion, wheat, rye, barley,
 *    apple, pear, mango, watermelon, honey, high fructose, inulin, chicory,
 *    beans, lentils, chickpeas, cashew, pistachio, milk, yogurt, whey, ice cream, soft
 *    cheese, mushroom, cauliflower, asparagus, sorbitol, mannitol, xylitol, maltitol).
 *  - USDA umbrella categories "Cereal Grains and Pasta" and "Dairy and Egg
 *    Products" are ignored by the keywords, since they mix foods.
 *  - Foundation/SR (generic) foods have no ingredients, so the USDA category gates every
 *    flag: a flag can be true only when the category makes it plausible, and no keyword
 *    rules it out. Plants (vegetables, fruits, legumes, nuts and seeds, spices, fats and
 *    oils, beverages) may get all flags. In any category, a name that marks a prepared dish
 *    or product (souffle, pancake, salad, soup, sauce, dressing, mix, supplement, recipe...)
 *    or carries an upper-case brand (ABBOTT, ENSURE PLUS) gets none: its ingredients are unknown. Beef, pork, poultry,
 *    lamb/game and fish may get dairy_free, gluten_free, grain_free, low_fodmap and paleo.
 *    Dairy and Egg may get gluten_free, grain_free and vegetarian, plus dairy_free, low_fodmap
 *    and paleo for egg foods only (name has "egg" and no dairy word). Cereal Grains and Pasta
 *    may get dairy_free, vegetarian and vegan (not vegan when the name has fresh, egg or noodle),
 *    plus gluten_free and low_fodmap when the name is rice, corn, quinoa, millet, sorghum,
 *    buckwheat, amaranth or teff (never oats). Any other or missing category (Baked Products,
 *    Sweets, Snacks, Sausages and Luncheon Meats...) gets every flag false.
 *  - Open Food Facts tags win: en:vegan, en:vegetarian, en:non-vegan,
 *    en:non-vegetarian, allergen or trace en:gluten (gluten_free false), en:milk (dairy_free false).
 *
 * @phpstan-type DietFlags array{dairy_free: bool, gluten_free: bool, grain_free: bool, low_fodmap: bool, vegetarian: bool, paleo: bool, vegan: bool}
 */
final class DietClassifier
{
    private const FLESH = ['beef', 'pork', 'chicken', 'turkey', 'lamb', 'veal', 'ham', 'bacon', 'sausage', 'salami',
        'pepperoni', 'duck', 'goose', 'venison', 'bison', 'meat', 'steak', 'liver', 'lard', 'tallow', 'suet', 'poultry',
        'fish', 'salmon', 'tuna', 'cod', 'tilapia', 'trout', 'sardine', 'anchovy', 'anchovies', 'mackerel', 'herring',
        'shrimp', 'prawn', 'crab', 'lobster', 'oyster', 'clam', 'mussel', 'scallop', 'squid', 'seafood', 'shellfish',
        'gelatin', 'gelatine', 'pancetta', 'chorizo', 'prosciutto', 'pastrami', 'mutton', 'goat meat', 'rabbit', 'jerky',
        'rennet', 'carmine', 'cochineal', 'collagen', 'isinglass'];

    private const DAIRY = ['milk', 'cheese', 'butter', 'buttermilk', 'cream', 'whey', 'casein', 'caseinate', 'yogurt',
        'yoghurt', 'lactose', 'kefir', 'ghee', 'custard', 'ricotta', 'mascarpone', 'mozzarella', 'parmesan', 'cheddar',
        'paneer', 'curd', 'milkfat', 'milk solids', 'quark', 'skyr'];

    private const EGG_HONEY = ['egg', 'honey'];

    private const SUBSTITUTE_CATEGORY = '/\b(?:substitutes?|alternatives?|analogues?|analogs?|imitation|meatless|plant\s+based|non\s+dairy|dairy\s+free|vegan|vegetarian)\b/';

    private const GLUTEN = ['wheat', 'barley', 'rye', 'spelt', 'malt', 'semolina', 'couscous', 'seitan', 'bread', 'pasta',
        'flour', 'bulgur', 'farro', 'kamut', 'beer', 'lager', 'ale', 'breaded', 'breading', 'batter', 'battered', 'crumb',
        'crumbs', 'oat', 'gluten', 'triticale', 'durum', 'graham',
        // Words match whole, so the compounds are listed: "oatmeal" is not "oat", "breadcrumbs" is not "bread".
        'oatmeal', 'breadcrumb', 'flatbread', 'shortbread', 'cornbread', 'gingerbread', 'malted'];

    private const OTHER_GRAINS = ['rice', 'corn', 'cornstarch', 'popcorn', 'maize', 'oat', 'quinoa', 'millet', 'sorghum',
        'buckwheat', 'cereal', 'noodle', 'granola', 'tortilla', 'cornmeal', 'cornflour', 'polenta', 'grits', 'amaranth',
        'teff', 'masa'];

    private const LEGUMES = ['bean', 'lentil', 'chickpea', 'garbanzo', 'pea', 'peanut', 'soy', 'soya', 'tofu', 'tempeh',
        'edamame', 'hummus', 'lupin', 'legume', 'soybean'];

    private const SUGAR = ['sugar', 'syrup', 'sweetener', 'dextrose', 'sucrose', 'fructose', 'glucose', 'sorbitol',
        'mannitol', 'xylitol', 'maltitol', 'sweetened', 'cola', 'soda', 'juice cocktail'];

    private const HIGH_FODMAP = ['garlic', 'onion', 'wheat', 'rye', 'barley', 'apple', 'pear', 'mango', 'watermelon',
        'honey', 'high fructose', 'inulin', 'chicory', 'bean', 'soybean', 'lentil', 'chickpea', 'cashew', 'pistachio', 'milk',
        'yogurt', 'yoghurt', 'whey', 'ice cream', 'soft cheese', 'ricotta', 'cream cheese', 'cottage cheese', 'mascarpone',
        'mushroom', 'cauliflower', 'asparagus', 'sorbitol', 'mannitol', 'xylitol', 'maltitol', 'hummus', 'agave', 'beer'];

    /** Plant products whose names contain a dairy word. */
    private const NOT_DAIRY = ['peanut butter', 'almond butter', 'cashew butter', 'nut butter', 'seed butter',
        'sunflower butter', 'cocoa butter', 'shea butter', 'butter bean', 'butternut', 'coconut milk', 'almond milk',
        'oat milk', 'soy milk', 'soya milk', 'rice milk', 'hemp milk', 'cashew milk', 'coconut cream', 'cream of tartar',
        'bean curd', 'soy curd'];

    /** Flours that are not gluten grains. Rice, corn, oat and buckwheat flour are still grains. */
    private const NON_GRAIN_FLOURS = ['almond', 'coconut', 'tapioca', 'potato', 'chickpea', 'cassava', 'arrowroot',
        'tigernut', 'hazelnut', 'banana'];

    /** Oat flour is left out: oats are usually cross-contaminated, so they count as gluten. */
    private const GLUTEN_FREE_FLOURS = ['rice', 'corn', 'buckwheat', 'sorghum', 'quinoa', 'millet'];

    /** USDA umbrella categories that mix grains with non-grains, or dairy with egg; they say nothing about one food. */
    private const UMBRELLA_CATEGORIES = ['cereal grains and pasta', 'dairy and egg products'];

    private const ALL_FLAGS = ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan'];

    /** Generic foods: the only flags a USDA food category allows to be true (a keyword can still switch them off). */
    private const MEAT_FLAGS = ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'paleo'];

    private const GENERIC_CATEGORIES = [
        'vegetables and vegetable products' => self::ALL_FLAGS,
        'fruits and fruit juices' => self::ALL_FLAGS,
        'legumes and legume products' => self::ALL_FLAGS,
        'nut and seed products' => self::ALL_FLAGS,
        'spices and herbs' => self::ALL_FLAGS,
        'fats and oils' => self::ALL_FLAGS,
        'beverages' => self::ALL_FLAGS,
        'beef products' => self::MEAT_FLAGS,
        'pork products' => self::MEAT_FLAGS,
        'poultry products' => self::MEAT_FLAGS,
        'lamb, veal, and game products' => self::MEAT_FLAGS,
        'finfish and shellfish products' => self::MEAT_FLAGS,
        'dairy and egg products' => ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo'],
    ];

    private const GRAIN_CATEGORY = 'cereal grains and pasta';

    private const DAIRY_EGG_CATEGORY = 'dairy and egg products';

    /** Generic names that are prepared dishes or products: their ingredients are unknown, so no flag. */
    private const PREPARED = ['dressing', 'mayonnaise', 'sauce', 'spread', 'margarine', 'dip', 'gravy', 'souffle',
        'pancake', 'fritter', 'salad', 'coleslaw', 'soup', 'stew', 'chowder', 'casserole', 'pie', 'pudding', 'dessert',
        'gratin', 'scalloped', 'recipe', 'mix', 'supplement', 'formula', 'shake', 'smoothie', 'drink', 'puff', 'patty',
        'nugget', 'babyfood', 'entree',
        // Plurals other than -s and -es, and compounds, are listed: has() matches whole words.
        'patties', 'meatloaf', 'meatball'];

    /** Pasta that is probably made with egg. */
    private const EGG_PASTA = ['fresh', 'egg', 'noodle'];

    /** Grains and flours that never contain gluten. Oats are left out: they are usually cross-contaminated. */
    private const GLUTEN_FREE_GRAIN_NAMES = ['rice', 'corn', 'cornmeal', 'maize', 'quinoa', 'millet', 'sorghum', 'buckwheat',
        'amaranth', 'teff'];

    /**
     * @param  list<string>  $tags  Open Food Facts ingredients_analysis_tags, allergens_tags and traces_tags
     * @param  bool  $generic  true for USDA Foundation/SR foods, which have no ingredient list
     * @return DietFlags
     */
    public function classify(string $name, ?string $category, ?string $ingredients, array $tags = [], bool $generic = false): array
    {
        $flags = array_fill_keys(array_column(DietFilter::cases(), 'value'), false);
        $ingredients = trim((string) $ingredients);

        if ($generic) {
            $flags = $this->limitToCategory($this->fromKeywords($name, $category, $ingredients), $name, $category);
        } elseif ($ingredients !== '') {
            $flags = $this->fromKeywords($name, $category, $ingredients);
        }

        return $this->applyTags($flags, $tags);
    }

    /** @return DietFlags */
    private function fromKeywords(string $name, ?string $category, string $ingredients): array
    {
        $category = str_replace(self::UMBRELLA_CATEGORIES, '', mb_strtolower((string) $category));
        // A category that names a substitute ("Plant Based Milk", "Vegetarian Frozen Meats", OFF's "meat analogues")
        // says what the food replaces, not what is in it, so its words must not rule a diet out.
        $category = implode(', ', array_filter(explode(',', $category), fn (string $part) => ! preg_match(self::SUBSTITUTE_CATEGORY, $part)));
        $text = mb_strtolower($name.' '.$category.' '.$ingredients);

        $dairyText = str_replace(self::NOT_DAIRY, '', $text);
        // The FODMAP check drops only the dairy word of a plant milk or butter: "cashew butter" keeps "cashew", "butter bean" keeps "bean".
        $fodmapText = str_replace(self::NOT_DAIRY, array_map(fn (string $p) => (string) preg_replace('/\b(?:butter|milk|cream)\b/', '', $p), self::NOT_DAIRY), $text);
        $glutenText = preg_replace($this->flourPattern([...self::NON_GRAIN_FLOURS, ...self::GLUTEN_FREE_FLOURS]), '', $text);
        $grainText = preg_replace($this->flourPattern(self::NON_GRAIN_FLOURS), '', $text);

        $flesh = $this->has(self::FLESH, $text);
        $dairy = $this->has(self::DAIRY, $dairyText);
        $eggHoney = $this->has(self::EGG_HONEY, $text);
        $gluten = $this->has(self::GLUTEN, $glutenText);
        $grain = $this->has([...self::GLUTEN, ...self::OTHER_GRAINS], $grainText);
        $legume = $this->has(self::LEGUMES, $text);
        $sugar = $this->has(self::SUGAR, $text);

        return [
            'dairy_free' => ! $dairy,
            'gluten_free' => ! $gluten,
            'grain_free' => ! $grain,
            'low_fodmap' => ! $this->has(self::HIGH_FODMAP, $fodmapText),
            'vegetarian' => ! $flesh,
            'paleo' => ! $dairy && ! $grain && ! $legume && ! $sugar,
            'vegan' => ! $flesh && ! $dairy && ! $eggHoney,
        ];
    }

    /**
     * A generic food gets a flag only when its USDA category makes that flag plausible; unknown categories get none.
     *
     * @param  DietFlags  $flags
     * @return DietFlags
     */
    private function limitToCategory(array $flags, string $name, ?string $category): array
    {
        $category = mb_strtolower(trim((string) $category));
        // An upper-case word is a brand (SR Legacy writes "Beverages, ABBOTT, ENSURE PLUS").
        if ($this->has(self::PREPARED, mb_strtolower($name)) || preg_match('/\b\p{Lu}{3,}\b/u', $name)) {
            return array_map(fn () => false, $flags);
        }

        if ($category === self::GRAIN_CATEGORY) {
            $safeGrain = $this->has(self::GLUTEN_FREE_GRAIN_NAMES, mb_strtolower($name));
            $allowed = $safeGrain ? ['dairy_free', 'gluten_free', 'low_fodmap', 'vegetarian', 'vegan'] : ['dairy_free', 'vegetarian', 'vegan'];
            if ($this->has(self::EGG_PASTA, mb_strtolower($name))) {
                $allowed = array_diff($allowed, ['vegan']);
            }
        } elseif ($category === self::DAIRY_EGG_CATEGORY) {
            $allowed = $this->isEggFood($name) ? ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo'] : ['gluten_free', 'grain_free', 'vegetarian'];
        } else {
            $allowed = self::GENERIC_CATEGORIES[$category] ?? [];
        }

        foreach ($flags as $flag => $value) {
            $flags[$flag] = $value && in_array($flag, $allowed, true);
        }

        return $flags;
    }

    /** The category mixes dairy and egg, so only a name with "egg" and no dairy word is trusted as an egg food. */
    private function isEggFood(string $name): bool
    {
        $name = mb_strtolower($name);

        return $this->has(['egg'], $name) && ! $this->has(self::DAIRY, str_replace(self::NOT_DAIRY, '', $name));
    }

    /**
     * Open Food Facts tags beat the keyword rules; negative tags beat positive ones.
     *
     * @param  DietFlags  $flags
     * @param  list<string>  $tags
     * @return DietFlags
     */
    private function applyTags(array $flags, array $tags): array
    {
        $tags = array_map('strtolower', $tags);

        if (in_array('en:vegan', $tags, true)) {
            $flags['vegan'] = $flags['vegetarian'] = true;
        }
        if (in_array('en:vegetarian', $tags, true)) {
            $flags['vegetarian'] = true;
        }
        if (in_array('en:non-vegan', $tags, true)) {
            $flags['vegan'] = false;
        }
        if (in_array('en:non-vegetarian', $tags, true)) {
            $flags['vegetarian'] = $flags['vegan'] = false;
        }
        if (in_array('en:gluten', $tags, true)) {
            $flags['gluten_free'] = false;
        }
        if (in_array('en:milk', $tags, true)) {
            $flags['dairy_free'] = false;
        }

        return $flags;
    }

    /** @param  list<string>  $words */
    private function has(array $words, string $text): bool
    {
        $alternatives = array_map(
            fn (string $w) => str_replace(' ', '\s+', preg_quote($w, '/')),
            $words,
        );

        return (bool) preg_match('/\b(?:'.implode('|', $alternatives).')(?:e?s)?\b/', $text);
    }

    /** @param  list<string>  $prefixes */
    private function flourPattern(array $prefixes): string
    {
        return '/\b(?:'.implode('|', $prefixes).')s?\s+flour\b/';
    }
}

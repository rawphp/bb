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
 *    and paleo for plain egg only (name has "egg", one of raw, whole, dried, boiled, poached,
 *    yolk or white, and no dairy word; scrambled, omelet and fried egg dishes are prepared). Cereal Grains and Pasta
 *    may get dairy_free, vegetarian and vegan (not vegan when the name has fresh, egg or noodle),
 *    plus gluten_free and low_fodmap when the name is rice, corn, quinoa, millet, sorghum,
 *    buckwheat, amaranth or teff (never oats). Any other or missing category (Baked Products,
 *    Sweets, Snacks, Sausages and Luncheon Meats...) gets every flag false.
 *  - The "no" rules are one table, SIGNALS, applied last: dairy turns off dairy_free, vegan
 *    and paleo; gluten turns off gluten_free, grain_free and paleo; egg and honey turn off
 *    vegan; meat, fish and shellfish turn off vegetarian and vegan. The keywords give these
 *    signals, and so do Open Food Facts tags (TAG_SIGNALS): en:milk, en:gluten, en:eggs,
 *    en:fish, en:crustaceans, en:molluscs, en:non-vegetarian, en:non-vegan. A trace of gluten
 *    or milk (DumpRow passes trace:en:...) turns off gluten_free or dairy_free only.
 *  - en:vegan and en:vegetarian only ever set those flags true, before the table, so they
 *    stand in for missing ingredients and never undo a "no".
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

    private const EGG = ['egg', 'mayonnaise', 'mayo', 'albumen', 'albumin', 'meringue'];

    private const HONEY = ['honey'];

    /**
     * The one table of "no" signals, applied last: each signal, from a keyword or an Open Food Facts tag,
     * switches these flags off, and nothing switches them back on.
     */
    private const SIGNALS = [
        'dairy' => ['dairy_free', 'vegan', 'paleo'],
        'gluten' => ['gluten_free', 'grain_free', 'paleo'],
        'egg' => ['vegan'],
        'honey' => ['vegan'],
        'meat' => ['vegetarian', 'vegan'],
        'animal' => ['vegan'],
        // A trace ("may contain") is a cross-contact risk: it matters for gluten and milk, not for vegan or paleo.
        'gluten trace' => ['gluten_free'],
        'milk trace' => ['dairy_free'],
    ];

    /** Open Food Facts tags that give a signal. DumpRow passes traces_tags as trace:en:... */
    private const TAG_SIGNALS = [
        'en:milk' => 'dairy',
        'en:gluten' => 'gluten',
        'en:eggs' => 'egg',
        'en:fish' => 'meat',
        'en:crustaceans' => 'meat',
        'en:molluscs' => 'meat',
        'en:non-vegetarian' => 'meat',
        'en:non-vegan' => 'animal',
        'trace:en:gluten' => 'gluten trace',
        'trace:en:milk' => 'milk trace',
    ];

    /** A generic Dairy and Egg Products name is plain egg only with one of these words. */
    private const PLAIN_EGG = ['raw', 'whole', 'dried', 'boiled', 'poached', 'yolk', 'white'];

    private const SUBSTITUTE_CATEGORY = '/\b(?:substitutes?|alternatives?|analogues?|analogs?|imitation|meatless|plant\s+based|non\s+dairy|dairy\s+free|vegan|vegetarian)\b/';

    private const GLUTEN = ['wheat', 'barley', 'rye', 'spelt', 'malt', 'semolina', 'couscous', 'seitan', 'bread', 'pasta',
        'flour', 'bulgur', 'farro', 'kamut', 'beer', 'lager', 'ale', 'breaded', 'breading', 'batter', 'battered', 'crumb',
        'crumbs', 'oat', 'gluten', 'triticale', 'durum', 'graham',
        // Words match whole, so the compounds are listed: "oatmeal" is not "oat", "breadcrumbs" is not "bread".
        'oatmeal', 'breadcrumb', 'flatbread', 'shortbread', 'cornbread', 'gingerbread', 'malted',
        // Foods and sauces made from wheat whose names do not say so.
        'soy sauce', 'shoyu', 'teriyaki', 'orzo', 'farina', 'panko', 'udon', 'ramen', 'matzo', 'matzah', 'einkorn', 'emmer',
        'freekeh', 'bran', 'cracker', 'pretzel', 'crouton', "brewer's yeast", 'brewers yeast'];

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
        'bean curd', 'soy curd', 'walnut butter', 'hazelnut butter', 'pecan butter', 'macadamia butter', 'pistachio butter'];

    /** Flours that are not gluten grains. Rice, corn, oat and buckwheat flour are still grains. */
    private const NON_GRAIN_FLOURS = ['almond', 'coconut', 'tapioca', 'potato', 'chickpea', 'cassava', 'arrowroot',
        'tigernut', 'hazelnut', 'banana'];

    /** Oat flour is left out: oats are usually cross-contaminated, so they count as gluten. */
    private const GLUTEN_FREE_FLOURS = ['rice', 'corn', 'buckwheat', 'sorghum', 'quinoa', 'millet'];

    /** USDA umbrella categories that mix grains with non-grains, or dairy with egg; they say nothing about one food. */
    private const UMBRELLA_CATEGORIES = ['cereal grains and pasta', 'dairy and egg products'];

    /** In GENERIC_CATEGORIES: the category allows every DietFilter flag (a keyword still decides each one). */
    private const ALL_FLAGS = true;

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
        'patties', 'meatloaf', 'meatball',
        // Cooked egg dishes are made with milk and fat; an egg substitute is not egg.
        'scrambled', 'omelet', 'omelette', 'fried', 'substitute'];

    /** Pasta that is probably made with egg. */
    private const EGG_PASTA = ['fresh', 'egg', 'noodle'];

    /** Grains and flours that never contain gluten. Oats are left out: they are usually cross-contaminated. */
    private const GLUTEN_FREE_GRAIN_NAMES = ['rice', 'corn', 'cornmeal', 'maize', 'quinoa', 'millet', 'sorghum', 'buckwheat',
        'amaranth', 'teff'];

    /**
     * @param  list<string>  $tags  Open Food Facts ingredients_analysis_tags and allergens_tags, and traces_tags as trace:en:...
     * @param  bool  $generic  true for USDA Foundation/SR foods, which have no ingredient list
     * @return DietFlags
     */
    public function classify(string $name, ?string $category, ?string $ingredients, array $tags = [], bool $generic = false): array
    {
        $flags = $this->none();
        $signals = [];
        $ingredients = trim((string) $ingredients);

        if ($generic || $ingredients !== '') {
            [$flags, $signals] = $this->fromKeywords($name, $category, $ingredients);
            if ($generic) {
                $flags = $this->limitToCategory($flags, $name, $category);
            }
        }

        // Positive tags only ever set a flag true, and run before the signals, so they never undo a "no".
        $tags = array_map('strtolower', $tags);
        if (in_array('en:vegan', $tags, true)) {
            $flags['vegan'] = $flags['vegetarian'] = true;
        }
        if (in_array('en:vegetarian', $tags, true)) {
            $flags['vegetarian'] = true;
        }
        foreach ($tags as $tag) {
            if (isset(self::TAG_SIGNALS[$tag])) {
                $signals[] = self::TAG_SIGNALS[$tag];
            }
        }

        foreach (array_unique($signals) as $signal) {
            foreach (self::SIGNALS[$signal] as $flag) {
                $flags[$flag] = false;
            }
        }

        return $flags;
    }

    /**
     * The flags the keywords allow, and the signals they found (keys of SIGNALS), which classify() applies last.
     *
     * @return array{DietFlags, list<string>}
     */
    private function fromKeywords(string $name, ?string $category, string $ingredients): array
    {
        $category = (string) preg_replace('/\b(?:'.implode('|', array_map(fn (string $c) => preg_quote($c, '/'), self::UMBRELLA_CATEGORIES)).')\b/', '', mb_strtolower((string) $category));
        // A category that names a substitute ("Plant Based Milk", "Vegetarian Frozen Meats", OFF's "meat analogues")
        // says what the food replaces, not what is in it, so its words must not rule a diet out.
        $category = implode(', ', array_filter(explode(',', $category), fn (string $part) => ! preg_match(self::SUBSTITUTE_CATEGORY, $part)));
        $text = mb_strtolower($name.' '.$category.' '.$ingredients);

        $dairyText = $this->withoutPlantDairy($text);
        // The FODMAP check drops only the dairy word of a plant milk or butter: "cashew butter" keeps "cashew", "butter bean" keeps "bean".
        $fodmapText = $this->withoutPlantDairy($text, keepPlant: true);
        $glutenText = (string) preg_replace($this->flourPattern([...self::NON_GRAIN_FLOURS, ...self::GLUTEN_FREE_FLOURS]), '', $text);
        $grainText = (string) preg_replace($this->flourPattern(self::NON_GRAIN_FLOURS), '', $text);

        $signals = array_keys(array_filter([
            'dairy' => $this->has(self::DAIRY, $dairyText),
            'gluten' => $this->has(self::GLUTEN, $glutenText),
            'egg' => $this->has(self::EGG, $text),
            'honey' => $this->has(self::HONEY, $text),
            'meat' => $this->has(self::FLESH, $text),
        ]));
        $grain = $this->has([...self::GLUTEN, ...self::OTHER_GRAINS], $grainText);

        // On top of none(): a diet DietFilter gains later stays false until a rule here decides it.
        $flags = array_replace($this->none(), [
            'dairy_free' => true,
            'gluten_free' => true,
            'grain_free' => ! $grain,
            'low_fodmap' => ! $this->has(self::HIGH_FODMAP, $fodmapText),
            'vegetarian' => true,
            'paleo' => ! $grain && ! $this->has(self::LEGUMES, $text) && ! $this->has(self::SUGAR, $text),
            'vegan' => true,
        ]);

        return [$flags, $signals];
    }

    /**
     * Every DietFilter flag, false: the one list of diets, so the classifier never drifts from the enum.
     *
     * @return DietFlags
     */
    private function none(): array
    {
        /** @var DietFlags */
        return array_fill_keys(array_column(DietFilter::cases(), 'value'), false);
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
            if ($allowed === self::ALL_FLAGS) {
                $allowed = array_keys($flags);
            }
        }

        foreach ($flags as $flag => $value) {
            $flags[$flag] = $value && in_array($flag, $allowed, true);
        }

        return $flags;
    }

    /** The category mixes dairy and egg, so only a plain egg name (raw, whole, boiled...) with no dairy word is trusted as an egg food. */
    private function isEggFood(string $name): bool
    {
        $name = mb_strtolower($name);

        return $this->has(['egg'], $name) && $this->has(self::PLAIN_EGG, $name)
            && ! $this->has(self::DAIRY, $this->withoutPlantDairy($name));
    }

    /**
     * Removes the plant milks and butters (NOT_DAIRY) as whole words, so "goat milk" keeps its milk
     * ("oat milk" is not inside it). With $keepPlant, only their dairy word goes.
     */
    private function withoutPlantDairy(string $text, bool $keepPlant = false): string
    {
        $phrases = implode('|', array_map(fn (string $p) => str_replace(' ', '\s+', preg_quote($p, '/')), self::NOT_DAIRY));

        return (string) preg_replace_callback(
            '/\b(?:'.$phrases.')s?\b/',
            fn (array $m) => $keepPlant ? (string) preg_replace('/\b(?:butter|milk|cream)\b/', '', $m[0]) : '',
            $text,
        );
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

# 06. Food and meals

The model is plan first, as in RP Diet. The user picks the foods for a meal and the app scales the grams to hit that meal's targets. The app never picks foods by itself, apart from AI recipes the user asks for (RCP-010).

## Food database (FOOD)

- **FOOD-001** [D] The app must include a food database of at least 750,000 foods, covering generic, packaged and restaurant items.
- **FOOD-002** [D] A curated "verified" subset (the app's equivalent of RP Foods) must be marked with a badge and ranked first in search.
- **FOOD-003** [D] Curated foods must be grouped: Protein (Recommended, Use sparingly, Before bed), Veggies, Carbs (Recommended, Use sparingly), Fat (Recommended, Use sparingly).
- **FOOD-004** [D] Each food must store calories, protein, fat, carbs, fiber, serving sizes in grams, and household units where they apply (slices, cups, tablespoons, pieces).
- **FOOD-005** [D] Amounts must show in grams with the household unit beside them, for example "105 g (about 2.6 slices)".
- **FOOD-006** [D] Foods that change weight when cooked (meat, rice, pasta, grains, potatoes) must have a raw and a cooked value and a per-food Raw/Cooked toggle. Both hit the same macros. Help text: weigh raw when cooking one meal, cooked when batch cooking.
- **FOOD-007** [D] Cooking oils must be foods like any other, and help text must tell the user to measure them.
- **FOOD-008** [D] The database must include drinks.
- **FOOD-009** [D] Food amounts must show and accept grams or ounces, with a unit chip beside the amount. The choice persists per user.

## Pantry and search (FOOD)

- **FOOD-020** [D] The Pantry (food picker) must have tabs: Curated, Recents, Favorites, Custom. Recipes and Saved Meals appear as filters.
- **FOOD-021** [D] The Curated tab must show each group with 5 foods and a "Show 25 more" link.
- **FOOD-022** [D] Users must be able to favorite a food by swiping it or tapping its image.
- **FOOD-023** [D] Search must show matches from Recents, Favorites, Custom and Curated first, then "More results" from the full database.
- **FOOD-024** [D] Search must correct typos ("Did you mean chicken breast?").
- **FOOD-025** [D] A source filter must narrow results to Recents, Favorites, Curated, Custom, Restaurants or Packaged.
- **FOOD-026** [D] A curated-group filter must narrow results to the groups in FOOD-003.
- **FOOD-027** [D] Diet filters must hide foods that don't match: Dairy free, Gluten free, Grain free, Low FODMAP, Ovo-lacto vegetarian, Paleo, Vegan. When several are on, a food must match all of them. Filters persist per user.
- **FOOD-028** [D] Selected foods must collect in a basket marked "Adding to meal", with foods already in the meal marked "Already in meal". Confirming adds them all at once.
- **FOOD-029** [D] Search must return first results in under 300 ms for local matches [G].

## Scanning (FOOD)

- **FOOD-040** [D] One camera button must offer three modes: Barcode, Nutrition label, and Photo.
- **FOOD-041** [D] Barcode mode must look up the product and add it to the basket. If not found, it must offer label scan or a custom food with the barcode filled in.
- **FOOD-042** [D] Label mode must read a nutrition label with AI and create a custom food from it for the user to confirm.
- **FOOD-043** [D] Photo mode must estimate each food and its portion from a photo of the plate, as an editable ingredient list matched to database foods.
- **FOOD-044** [D] Photo mode must also accept a typed description instead of a photo ("chicken burrito bowl with rice").
- **FOOD-045** [D] A portion-size control must scale the whole scanned meal up or down.
- **FOOD-046** [D] A scanned meal must be savable as a Saved Meal (MEAL-070).

## Custom foods (FOOD)

- **FOOD-060** [D] Users must be able to create custom foods with name, brand, barcode, serving size and unit (grams, cups, spoons, pieces, weight per serving), fat, carbs, fiber and protein. Calories are computed and shown.
- **FOOD-061** [D] A Custom foods manager (More menu) must list, edit and delete custom foods.
- **FOOD-062** [D] Custom food search must say "Can't find it? Create a custom food" with the search text filled in as the name.

## Meal builder (MEAL)

- **MEAL-001** [D] Opening a meal must show its targets as four bars (calories, P, F, C) with consumed or planned against target, and the foods in it.
- **MEAL-002** [D] Adding foods must scale their grams so the meal hits its targets. The optimizer fits each food to the meal target rather than a standard serving.
- **MEAL-003** [D] A "Targets met" badge must show when every macro is within 5 g of target.
- **MEAL-004** [D] Users must be able to change a food's amount with + and − buttons or by typing grams or units. Unlocked foods rebalance to keep the meal on target.
- **MEAL-005** [D] A lock icon on a food must keep its exact amount through rebalancing, copying and saving.
- **MEAL-006** [D] A Rebalance button must re-fit all unlocked foods to the meal's targets.
- **MEAL-007** [D] When the foods chosen cannot hit the targets (for example no carb source), the meal must say which macro is short and suggest adding a food from that curated group. It must not add the food itself.
- **MEAL-008** [D] Swiping a food must delete it.
- **MEAL-009** [D] The meal menu must offer: Edit macros/time, Load saved meal, Save as saved meal, Copy foods, Remove foods, Check in meal, Check in at manual macros, Remove check-in, Still planning foods, Delete meal. Actions that need foods are disabled on an empty meal.
- **MEAL-010** [I] Peri-workout meals must show a "Pre-workout" or "Post-workout" tag and the workout's time. Help text says these meals carry most of the day's carbs.
- **MEAL-011** [D] The meal screen must show a status pill for calories and one for protein: "on track" when within 10% of the meal target, otherwise "N under" or "N over".

## Check-ins (MEAL)

- **MEAL-040** [D] Users must be able to check in a meal by: tapping its checkbox on Today, ticking it inside the meal, or "Check in meal" in the menu.
- **MEAL-041** [D] Swiping a meal card right must open manual check-in: edit time, calories and macros eaten.
- **MEAL-042** [D] Checking in an empty meal must count the meal's targets as eaten ("check in as given").
- **MEAL-043** [D] Checking in a meal whose foods are within 5 g of each target must mark it on track.
- **MEAL-044** [D] Users must be able to remove a check-in.
- **MEAL-045** [D] A meal with the "Adjust foods" badge (DAY-003) cannot be checked in until the user fixes it or marks it "Done planning foods", except inside the weekly review.
- **MEAL-046** [D] An empty meal must show the empty state "Your meal is empty" and the note "Because this meal has no foods in it, the app assumes you are eating this meal at target macros."
- **MEAL-047** [D] Each meal card and meal screen must show its check-in state with an icon: checked in, not checked in, or checked in under or over target.

## Copy and saved meals (MEAL)

- **MEAL-060** [D] Copy foods must copy a meal's foods to one or more meals on one or more days, chosen from a week strip and a list of target meals. The destination list must show workout blocks between meals so the day's order is visible. Advanced options:
  - Update destination target macros (on by default; the copied foods re-fit to the destination meal);
  - Update destination meal times (off by default; shakes keep their workout-relative time);
  - Copy locked state (off by default).
- **MEAL-070** [D] Users must be able to save a meal as a Saved Meal and load it into any meal. Locked foods load at their saved amounts and unlocked foods re-fit to the destination's targets.
- **MEAL-071** [D] Saved Meals must be listable, renameable and deletable.

## Day balance and redistribution (DAY)

- **DAY-001** [D] Users must be able to log food eaten off-plan ("Save food macros") as an extra entry on the day without a meal slot.
- **DAY-002** [D] A Redistribute button (wand icon) must spread the day's remaining calories and macros across later meals. The user taps which meals receive it and can exclude any.
- **DAY-003** [D] Redistribute must also work onto meals that already have foods (not locked ones). Those meals get an orange "Adjust foods" badge until their foods are re-fit.
- **DAY-004** [D] Each meal must have "Still planning foods" and "Done planning foods" states.
- **DAY-010** [D] The day-totals bar must show a status: "on track" when calories and protein are each within 10% of target, otherwise how far under or over ("315 under").
- **DAY-011** [D] By default, consumed totals must include planned but not checked-in meals. A setting switches to checked-in only.
- **DAY-020** [D] The Adjust sheet must let the user move calories between meals on the day, and between days in the week (NUT-021), showing projected day calories and protein.
- **DAY-021** [D] App copy must say small misses are fine: within 10% on calories and protein each day is success.

## Recipes (RCP)

- **RCP-001** [D] Users must be able to build a recipe from foods, set the number of servings, and add a serving to any meal.
- **RCP-002** [D] A recipe serving added to a meal must scale to the meal's targets unless locked.
- **RCP-003** [D] Users must be able to import a recipe from a URL. Ingredients are matched to database foods for the user to confirm.
- **RCP-010** [D] Users must be able to generate a recipe with AI from: target macros (default: the selected meal's), meal type, ingredients to use, complexity, and preferences (diet filters apply).
- **RCP-020** [D] The app must include a library of at least 150 ready-made recipes, filterable by meal type and diet filter.
- **RCP-021** [D] A recipe used across several days must update everywhere when edited, unless a day's copy is checked in.

## Shopping list (SHOP)

- **SHOP-001** [D] The shopping list must be built from planned foods for a chosen date range (default: the next 7 days).
- **SHOP-002** [D] Recipes must be broken down into their ingredients on the list.
- **SHOP-003** [G] Items must be grouped by store category (protein, produce, grains and bread, dairy, pantry, frozen, other) and summed across days, rounded up to the next friendly purchase amount.
- **SHOP-004** [G] Raw and cooked amounts must be converted to the raw weight to buy.
- **SHOP-005** [G] Users must be able to tick items off, add manual items, and share the list as text.
- **SHOP-006** [D] The shopping list must be reachable from the More menu and from the Nutrition tab.

## Eating out and alcohol (FOOD)

- **FOOD-080** [D] Restaurant items must be searchable, and the photo scanner must work on restaurant meals.
- **FOOD-081** [D] Help content must say: avoid alcohol while dieting, at most 1 to 2 drinks a week. Coffee and caffeine are fine.

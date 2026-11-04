import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readNow, writeNow } from '@/app/call';
import type { FoodSearchInput, FoodSearchOutput } from '@/contracts/food_search';
import type { MealFoodAddShowInput, MealFoodAddShowOutput } from '@/contracts/meal_food_add_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import PantryView from './PantryView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), readNow: vi.fn(), writeNow: vi.fn() }));
const back = vi.fn();
// The route on screen; a test changes it to leave the Pantry (another tab, a pushed screen).
const currentRoute = { value: { fullPath: '/nutrition/pantry' } };
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ back, currentRoute }) }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const previewMock = vi.mocked(readNow);
const preview = screenFixture<MealFoodAddShowOutput>('meal_food_add_show');
const previewMet = screenFixture<MealFoodAddShowOutput>('meal_food_add_show.met');
const search = screenFixture<FoodSearchOutput>('food_search');
const curated = screenFixture<FoodSearchOutput>('food_search.curated');
const didYouMean = screenFixture<FoodSearchOutput>('food_search.did-you-mean');
const empty = screenFixture<FoodSearchOutput>('food_search.empty');
const MEAL = '01JBX5M0000000000000000001';

function show(props: Record<string, unknown> = { payload: curated, mealId: MEAL }) {
    return mount(PantryView, { props, attachTo: document.body, global: { stubs: { RouterLink: RouterLinkStub, teleport: true } } });
}

function lastRead(): FoodSearchInput {
    return readMock.mock.calls.at(-1)![1] as FoodSearchInput;
}

function writes(): [string, Record<string, unknown>][] {
    return writeMock.mock.calls.map((call) => call.slice(0, 2) as [string, Record<string, unknown>]);
}

// jsdom has no PointerEvent, and Test Utils cannot set clientX on the MouseEvent it falls back to.
async function pointer(target: ReturnType<ReturnType<typeof show>['find']>, type: string, clientX: number, clientY: number) {
    target.element.dispatchEvent(new MouseEvent(type, { clientX, clientY, bubbles: true }));
    await flushPromises();
}

function row(wrapper: ReturnType<typeof show>, name: string) {
    const found = wrapper.findAll('[data-test="food-row"]').find((r) => r.find('[data-test="food-name"]').text() === name);
    if (!found) throw new Error(`No row for ${name}`);
    return found;
}

// Every test's PantryView is unmounted after it, so a search timer it left (250 ms after typing) cannot fire a
// read during a later test and become that test's lastRead().
enableAutoUnmount(afterEach);

describe('PantryView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(curated);
        writeMock.mockReset().mockResolvedValue({});
        // The basket's preview (FOOD-030) stays out unless a test answers it.
        previewMock.mockReset().mockReturnValue(new Promise(() => {}));
        back.mockReset();
        currentRoute.value = { fullPath: '/nutrition/pantry' };
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('FOOD-020 has the Curated, Recents, Favorites and Custom tabs, and a tab reads food_search for it', async () => {
        const wrapper = show();

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs.map((t) => t.text())).toEqual(['Curated', 'Recents', 'Favorites', 'Custom']);
        expect(tabs[0]!.attributes('aria-selected')).toBe('true');
        expect(readMock).not.toHaveBeenCalled();

        await tabs[1]!.trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('food_search', expect.anything(), expect.any(Function), expect.any(Function));
        expect(lastRead()).toMatchObject({ tab: 'recents', meal_id: MEAL, query: '' });
        expect(inputViolations('food_search', lastRead())).toEqual([]);
        expect(wrapper.findAll('[role="tab"]')[1]!.attributes('aria-selected')).toBe('true');
    });

    it('FOOD-020 shows Recipes and Saved Meals as a filter, in place of the tabs', async () => {
        const wrapper = show();

        await wrapper.find('select[aria-label="Show"]').setValue('recipes');
        await flushPromises();

        expect(lastRead()).toMatchObject({ collection: 'recipes' });
        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.find('select[aria-label="Source filter"]').exists()).toBe(false);
        const recipe = wrapper.find('[data-test="collection-row"]');
        expect(recipe.text()).toContain('Overnight oats');
        expect(recipe.text()).toContain('Breakfast · 330 kcal · P 28 · F 3 · C 47');
        const links = wrapper.findAllComponents(RouterLinkStub).map((l) => l.props('to'));
        expect(links).toContainEqual({ path: '/nutrition/recipes/generate', query: { meal: MEAL } });
        expect(links).toContainEqual('/nutrition/recipes/import');

        await wrapper.find('select[aria-label="Show"]').setValue('saved_meals');
        await flushPromises();

        expect(lastRead()).toMatchObject({ collection: 'saved_meals' });
        expect(wrapper.find('[data-test="collection-row"]').text()).toContain('Bulk dinner');
        expect(wrapper.find('[data-test="collection-row"]').text()).toContain('3 foods');
    });

    it('FOOD-021 shows each curated group with a Show 25 more link that opens the group', async () => {
        const wrapper = show();

        const sections = wrapper.findAll('[data-test="food-section"]');
        expect(sections.map((s) => s.find('h2').text())).toEqual(['Protein · Recommended', 'Veggies']);
        expect(sections[0]!.findAll('[data-test="food-row"]')).toHaveLength(2);
        const more = sections[0]!.find('[data-test="show-more"]');
        expect(more.text()).toBe('Show 25 more');
        expect(more.attributes('aria-label')).toBe('Show 25 more in Protein · Recommended');
        expect(sections[1]!.find('[data-test="show-more"]').exists()).toBe(false);

        await more.trigger('click');
        await flushPromises();

        expect(lastRead()).toMatchObject({ expanded_groups: ['protein_recommended'] });
        expect(inputViolations('food_search', lastRead())).toEqual([]);
    });

    it('FOOD-021 a second tap on Show 25 more asks for the group once', async () => {
        const wrapper = show();

        await wrapper.find('[data-test="show-more"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-test="show-more"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledOnce();
        expect(lastRead().expanded_groups).toEqual(['protein_recommended']);
    });

    it('FOOD-022 favorites a food by tapping its picture, sending a valid food_favorite_set', async () => {
        const wrapper = show();

        const breast = row(wrapper, 'Chicken breast');
        expect(breast.find('[data-test="favorite"]').attributes('aria-label')).toBe('Unfavorite Chicken breast');
        expect(breast.find('[data-test="favorite-star"]').exists()).toBe(true);
        await breast.find('[data-test="favorite"]').trigger('click');
        await flushPromises();

        expect(writes()).toEqual([['food_favorite_set', { food_id: '01JBX5F0000000000000000002', favorite: false }]]);
        expect(inputViolations('food_favorite_set', writes()[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalled();
    });

    it('FOOD-022 favorites a food by swiping its row', async () => {
        const wrapper = show();

        const eggs = row(wrapper, 'Egg whites');
        expect(eggs.find('[data-test="swipe-favorite"]').attributes('aria-hidden')).toBe('true');
        const swipe = eggs.find('[data-test="swipe"]');
        await pointer(swipe, 'pointerdown', 300, 10);
        await pointer(swipe, 'pointermove', 200, 12);
        await pointer(swipe, 'pointerup', 200, 12);

        const action = row(wrapper, 'Egg whites').find('[data-test="swipe-favorite"]');
        expect(action.attributes('aria-hidden')).toBeUndefined();
        expect(action.text()).toBe('Favorite');
        await action.trigger('click');
        await flushPromises();

        expect(writes()).toEqual([['food_favorite_set', { food_id: '01JBX5F0000000000000000007', favorite: true }]]);
    });

    it('FOOD-028 a tap that drifts a few pixels sideways still picks the food', async () => {
        const wrapper = show();

        const swipe = row(wrapper, 'Egg whites').find('[data-test="swipe"]');
        await pointer(swipe, 'pointerdown', 300, 10);
        await pointer(swipe, 'pointermove', 297, 11);
        await pointer(swipe, 'pointerup', 297, 11);
        await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');

        expect(row(wrapper, 'Egg whites').find('[data-test="pick"]').attributes('aria-pressed')).toBe('true');
        expect(row(wrapper, 'Egg whites').find('[data-test="swipe-favorite"]').attributes('aria-hidden')).toBe('true');
    });

    it('FOOD-022 a tap elsewhere closes the swipe action', async () => {
        const wrapper = show();

        const swipe = row(wrapper, 'Egg whites').find('[data-test="swipe"]');
        await pointer(swipe, 'pointerdown', 300, 10);
        await pointer(swipe, 'pointermove', 200, 12);
        await pointer(swipe, 'pointerup', 200, 12);
        expect(row(wrapper, 'Egg whites').find('[data-test="swipe-favorite"]').attributes('aria-hidden')).toBeUndefined();

        await pointer(wrapper.find('h1'), 'pointerdown', 10, 10);

        expect(row(wrapper, 'Egg whites').find('[data-test="swipe-favorite"]').attributes('aria-hidden')).toBe('true');
    });

    it('FOOD-022 a vertical drag scrolls and does not open the swipe action', async () => {
        const wrapper = show();

        const swipe = row(wrapper, 'Egg whites').find('[data-test="swipe"]');
        await pointer(swipe, 'pointerdown', 300, 10);
        await pointer(swipe, 'pointermove', 280, 120);
        await pointer(swipe, 'pointerup', 280, 120);

        expect(row(wrapper, 'Egg whites').find('[data-test="swipe-favorite"]').attributes('aria-hidden')).toBe('true');
    });

    it('FOOD-023 searches as the user types, showing the matches first and then More results', async () => {
        vi.useFakeTimers();
        readMock.mockResolvedValue(search);
        const wrapper = show();

        await wrapper.find('input[type="search"]').setValue('chicken');
        expect(readMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(1);
        expect(lastRead()).toMatchObject({ query: 'chicken', tab: 'curated', meal_id: MEAL });
        expect(inputViolations('food_search', lastRead())).toEqual([]);
        const sections = wrapper.findAll('[data-test="food-section"]');
        expect(sections[0]!.find('h2').exists()).toBe(false);
        expect(sections[0]!.findAll('[data-test="food-name"]').map((n) => n.text())).toEqual(['Chicken noodle soup', 'Chicken breast', 'Chicken thigh']);
        expect(sections[1]!.find('h2').text()).toBe('More results');
        expect(row(wrapper, 'Chicken noodle soup').text()).toContain("Campbell's");
    });

    it('FOOD-023 a later search that fails keeps the foods on screen and says why', async () => {
        const wrapper = show();
        readMock.mockRejectedValueOnce(new CapabilityError('food_search', 500, { ok: false }));

        await wrapper.findAll('[role="tab"]')[2]!.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="search-error"]').text()).toBe('The foods could not be loaded.');
        expect(wrapper.findAll('[data-test="food-row"]')).toHaveLength(3);
    });

    it('OFF-003 a later search with no connection keeps the foods and says search needs one', async () => {
        const wrapper = show();
        readMock.mockRejectedValueOnce(new CapabilityError('food_search', 0, { reason: 'network' }));

        await wrapper.findAll('[role="tab"]')[1]!.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="search-error"]').text()).toBe('Search needs a connection.');
        expect(wrapper.findAll('[data-test="food-row"]')).toHaveLength(3);
    });

    it('OFF-003 a later search answered 401 takes the foods away and asks to sign in', async () => {
        const wrapper = show();
        readMock.mockRejectedValueOnce(new CapabilityError('food_search', 401, null));

        await wrapper.findAll('[role="tab"]')[1]!.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="food-row"]').exists()).toBe(false);
        expect(wrapper.find('[role="alert"]').text()).toContain('You are signed out.');
        expect(wrapper.find('[data-test="sign-in"]').exists()).toBe(true);
    });

    it('OFF-003 a cached search whose refresh is refused is taken away', async () => {
        let failRefresh: (error: CapabilityError) => void = () => {};
        readMock.mockImplementationOnce(async (_n, _i, _u, onError) => {
            failRefresh = onError as typeof failRefresh;
            return search;
        });
        const wrapper = show();
        await wrapper.findAll('[role="tab"]')[1]!.trigger('click');
        await flushPromises();
        expect(row(wrapper, 'Chicken stock').exists()).toBe(true);

        failRefresh(new CapabilityError('food_search', 404, null));
        await flushPromises();

        expect(wrapper.find('[data-test="food-row"]').exists()).toBe(false);
        expect(wrapper.find('[role="alert"]').text()).toContain('The foods could not be loaded.');
    });

    it('CAP-012 a refused favorite says why in the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('food_favorite_set', 422, { ok: false, error: { message: 'That food is gone.' } }));
        const wrapper = show();

        await row(wrapper, 'Egg whites').find('[data-test="favorite"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="search-error"]').text()).toBe('That food is gone.');
    });

    it('FOOD-024 offers the typo correction, and tapping it searches for that', async () => {
        vi.useFakeTimers();
        const wrapper = show({ payload: didYouMean, mealId: MEAL });

        const suggestion = wrapper.find('[data-test="suggestion"]');
        expect(suggestion.text()).toBe('Did you mean chicken breast?');
        expect(wrapper.find('[data-test="empty"]').text()).toBe('No foods match chiken brest.');
        await suggestion.trigger('click');
        await vi.advanceTimersByTimeAsync(300);

        expect(lastRead()).toMatchObject({ query: 'chicken breast' });
        expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe('chicken breast');
    });

    it('FOOD-025 narrows by source, and says the filter needs a search', async () => {
        const wrapper = show();

        const chips = wrapper.find('select[aria-label="Source filter"]');
        expect(chips.findAll('option').map((o) => o.text())).toEqual(['All sources', 'Recents', 'Favorites', 'Curated', 'Custom', 'Restaurants', 'Packaged']);
        await chips.setValue('restaurants');
        await flushPromises();

        expect(lastRead()).toMatchObject({ source: 'restaurants' });
        expect(inputViolations('food_search', lastRead())).toEqual([]);
        expect(wrapper.find('[data-test="source-chip"]').text()).toBe('Restaurants');
        expect(wrapper.text()).toContain('The source filter narrows search results. Type to search.');
    });

    it('FOOD-025 a search of only spaces still shows the source hint', async () => {
        const wrapper = show();

        await wrapper.find('select[aria-label="Source filter"]').setValue('packaged');
        await wrapper.find('input[type="search"]').setValue('   ');

        expect(wrapper.text()).toContain('The source filter narrows search results. Type to search.');
    });

    it('FOOD-026 narrows by curated group, and opens on the group it was asked for', async () => {
        readMock.mockReturnValue(new Promise(() => {}));
        show({ mealId: MEAL, group: 'carbs_recommended', search: 'rice' });
        await flushPromises();

        expect(lastRead()).toMatchObject({ group: 'carbs_recommended', query: 'rice', meal_id: MEAL });

        readMock.mockResolvedValue(curated);
        const wrapper = show();
        const groups = wrapper.find('select[aria-label="Group filter"]');
        expect(groups.findAll('option').map((o) => o.text())).toContain('Protein · Before bed');
        await groups.setValue('veggies');
        await flushPromises();

        expect(lastRead()).toMatchObject({ group: 'veggies' });
        expect(wrapper.find('[data-test="group-chip"]').text()).toBe('Veggies');
    });

    it('FOOD-027 shows the diet filters that are on, and a toggle saves them all with a valid diet_filters_set', async () => {
        const wrapper = show();

        expect(wrapper.find('[data-test="diet-chip"]').text()).toBe('Diet · 1');
        await wrapper.find('[data-test="diet-chip"]').trigger('click');
        const toggles = wrapper.findAll('[data-test="diet"]');
        expect(toggles.map((t) => t.text())).toEqual(['Dairy free', 'Gluten free', 'Grain free', 'Low FODMAP', 'Ovo-lacto vegetarian', 'Paleo', 'Vegan']);
        expect(toggles[1]!.attributes('aria-pressed')).toBe('true');

        await toggles[6]!.trigger('click');
        await flushPromises();

        expect(writes()).toEqual([['diet_filters_set', { filters: [{ filter: 'gluten_free' }, { filter: 'vegan' }] }]]);
        expect(inputViolations('diet_filters_set', writes()[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalled();

        await wrapper.findAll('[data-test="diet"]')[1]!.trigger('click');
        await flushPromises();
        expect(writes()[1]).toEqual(['diet_filters_set', { filters: [] }]);
    });

    it('FOOD-028 collects picks in Adding to meal, marks Already in meal, and adds them all at once', async () => {
        const wrapper = show({ payload: search, mealId: MEAL });

        expect(wrapper.find('[data-test="already-in-meal"]').text()).toContain('Already in meal');
        expect(wrapper.find('[data-test="already-in-meal"]').text()).toContain('Beef eye of round');
        const confirm = wrapper.find('[data-test="confirm"]');
        expect(confirm.text()).toBe('Add to meal');
        expect(confirm.attributes('disabled')).toBeDefined();

        await row(wrapper, 'Chicken breast').find('[data-test="weighing-cooked"]').trigger('click');
        await row(wrapper, 'Chicken breast').find('[data-test="pick"]').trigger('click');
        await row(wrapper, 'Chicken noodle soup').find('[data-test="pick"]').trigger('click');

        const basket = wrapper.find('[data-test="basket"]');
        expect(basket.find('h2').text()).toBe('Adding to meal');
        expect(basket.findAll('li').map((li) => li.text())).toEqual(['Chicken breastCooked', "Chicken noodle soupCampbell's"]);
        expect(row(wrapper, 'Chicken breast').find('[data-test="pick"]').attributes('aria-label')).toBe('Remove Chicken breast from meal');
        expect(wrapper.find('[data-test="confirm"]').text()).toBe('Add 2 to meal');

        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();

        const [[name, input]] = writes() as [[string, { meal_id: string; entries: { id: string }[] }]];
        expect(name).toBe('meal_food_add');
        expect(input).toEqual({
            meal_id: MEAL,
            entries: [
                { id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), food_id: '01JBX5F0000000000000000002', weighing: 'cooked' },
                { id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), food_id: '01JBX5F0000000000000000003', weighing: 'raw' },
            ],
        });
        expect(input.entries[0]!.id).not.toBe(input.entries[1]!.id);
        expect(inputViolations(name, input)).toEqual([]);
        expect(back).toHaveBeenCalledOnce();
    });

    describe('FOOD-030 the basket at the foot shows what Add makes of the picks', () => {
        // The preview waits for the picks to settle (250 ms), as a search waits for typing.
        beforeEach(() => vi.useFakeTimers());

        async function settle(): Promise<void> {
            await vi.advanceTimersByTimeAsync(300);
            await flushPromises();
        }

        // Answers the preview for the entries sent, with the fixture's amounts in their order.
        function answer(fixture: MealFoodAddShowOutput) {
            return async (_name: string, input: Record<string, unknown> = {}) => {
                const entries = (input as unknown as MealFoodAddShowInput).entries;
                return { ...fixture, entries: entries.map((entry, index) => ({ id: entry.id, amount_text: fixture.entries[index]?.amount_text ?? null })) } as never;
            };
        }

        function previewInput(): MealFoodAddShowInput {
            return previewMock.mock.calls.at(-1)![1] as unknown as MealFoodAddShowInput;
        }

        it('FOOD-030 asks meal_food_add_show with the entries Add will send, and shows each pick\'s amount', async () => {
            previewMock.mockImplementation(answer(preview));
            const wrapper = show({ payload: search, mealId: MEAL });

            await row(wrapper, 'Chicken breast').find('[data-test="weighing-cooked"]').trigger('click');
            await row(wrapper, 'Chicken breast').find('[data-test="pick"]').trigger('click');
            await row(wrapper, 'Chicken noodle soup').find('[data-test="pick"]').trigger('click');
            await settle();

            expect(previewMock.mock.calls.at(-1)![0]).toBe('meal_food_add_show');
            expect(previewInput()).toMatchObject({
                meal_id: MEAL,
                entries: [
                    { food_id: '01JBX5F0000000000000000002', weighing: 'cooked' },
                    { food_id: '01JBX5F0000000000000000003', weighing: 'raw' },
                ],
            });
            expect(inputViolations('meal_food_add_show', previewInput())).toEqual([]);
            const basket = wrapper.find('[data-test="basket"]');
            expect(basket.findAll('li').map((li) => li.text())).toEqual(['Chicken breastCooked · 160 g (about 1.1 fillets)', "Chicken noodle soupCampbell's · 305 g (about 1.6 cups)"]);

            await wrapper.find('[data-test="confirm"]').trigger('click');
            await settle();
            // The preview named the very entries Add sent.
            expect((writes()[0]![1] as { entries: { id: string }[] }).entries.map((entry) => entry.id)).toEqual(previewInput().entries.map((entry) => entry.id));
        });

        it('FOOD-030 stands at the foot beside Add, where it stays in view while the foods scroll', async () => {
            const wrapper = show();

            await row(wrapper, 'Broccoli').find('[data-test="pick"]').trigger('click');

            const foot = wrapper.find('[data-test="confirm"]').element.parentElement!;
            expect(foot.contains(wrapper.find('[data-test="basket"]').element)).toBe(true);
            expect(wrapper.find('[data-test="pantry-content"] [data-test="basket"]').exists()).toBe(false);
        });

        it('FOOD-030 MEAL-001 MEAL-007 draws the meal\'s four bars after Add, and a short-macro hint opens its curated group', async () => {
            previewMock.mockImplementation(answer(preview));
            const wrapper = show();

            await row(wrapper, 'Broccoli').find('[data-test="pick"]').trigger('click');
            await settle();

            const bars = wrapper.findAll('[data-test="basket-bars"] [role="progressbar"]');
            expect(bars.map((bar) => bar.attributes('aria-label'))).toEqual(['Calories', 'Protein', 'Fat', 'Carbs']);
            expect(bars[1]!.attributes('aria-valuetext')).toBe('58 of 68');
            expect(wrapper.find('[data-test="basket"]').text()).not.toContain('Targets met');

            // Two bars to a row at every width, as the Nutrition cards have: four in the 40rem column leaves the
            // calorie track too short beside its value.
            const grid = wrapper.get('[data-test="basket-bars"]').classes();
            expect(grid).toContain('grid-cols-2');
            expect(grid.filter((name) => /^md:grid-cols-/.test(name))).toEqual([]);

            const hint = wrapper.find('[data-test="basket-hint"]');
            expect(hint.text()).toBe('Short 21 g fat. Add a food from Fat · Recommended.');
            // A full tap height, as the meal screen's hint has, clear of Add below it.
            expect(hint.classes()).toContain('min-h-tap');
            await hint.trigger('click');
            await settle();
            expect(lastRead()).toMatchObject({ group: 'fat_recommended' });
        });

        it('FOOD-030 MEAL-003 says Targets met when the picks reach the meal\'s targets', async () => {
            previewMock.mockImplementation(answer(previewMet));
            const wrapper = show();

            await row(wrapper, 'Broccoli').find('[data-test="pick"]').trigger('click');
            await settle();

            expect(wrapper.find('[data-test="basket"]').text()).toContain('Targets met');
            expect(wrapper.find('[data-test="basket-hint"]').exists()).toBe(false);
        });

        it('FOOD-030 asks again when a pick comes out or is weighed another way, and shows only the latest answer', async () => {
            const answers: ((value: never) => void)[] = [];
            previewMock.mockImplementation(() => new Promise((resolve) => answers.push(resolve)));
            const wrapper = show();

            await row(wrapper, 'Broccoli').find('[data-test="pick"]').trigger('click');
            await settle();
            await row(wrapper, 'Broccoli').find('[data-test="weighing-cooked"]').trigger('click');
            await settle();
            expect(previewMock).toHaveBeenCalledTimes(2);
            expect(previewInput().entries[0]).toMatchObject({ weighing: 'cooked' });

            const latest = { ...previewMet, entries: [{ id: previewInput().entries[0]!.id, amount_text: '90 g' }] };
            const older = { ...preview, entries: [{ id: previewInput().entries[0]!.id, amount_text: '400 g' }] };
            answers[1]!(latest as never);
            await settle();
            answers[0]!(older as never);
            await settle();
            expect(wrapper.find('[data-test="basket"] li').text()).toBe('BroccoliCooked · 90 g');

            await wrapper.find('[data-test="basket"] button[aria-label="Remove Broccoli"]').trigger('click');
            await settle();
            expect(previewMock).toHaveBeenCalledTimes(2);
            expect(wrapper.find('[data-test="basket"]').exists()).toBe(false);
        });

        it('FOOD-030 with a saved meal picked, or the preview not answering, lists the picks alone and Add still works', async () => {
            previewMock.mockImplementation(answer(preview));
            const wrapper = show();

            await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
            await wrapper.find('select[aria-label="Show"]').setValue('saved_meals');
            await settle();
            await wrapper.find('[data-test="collection-row"]').trigger('click');
            await settle();

            // A saved meal loads on its own write, so the fit cannot show the meal after it.
            expect(wrapper.find('[data-test="basket-bars"]').exists()).toBe(false);
            expect(wrapper.find('[data-test="basket"]').findAll('li').map((li) => li.text())).toEqual(['Egg whites', 'Bulk dinnerSaved meal']);

            await wrapper.find('[data-test="collection-row"]').trigger('click');
            await wrapper.find('select[aria-label="Show"]').setValue('');
            await settle();
            previewMock.mockRejectedValue(new CapabilityError('meal_food_add_show', 0, { reason: 'network' }));
            await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
            await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
            await settle();

            expect(wrapper.find('[data-test="basket-bars"]').exists()).toBe(false);
            expect(wrapper.find('[data-test="basket"] li').text()).toBe('Egg whites');
            expect(wrapper.find('[data-test="confirm"]').attributes('disabled')).toBeUndefined();
        });

        it('FOOD-030 asks once for a run of quick changes, after they settle, so the picks do not spend the rate limit', async () => {
            previewMock.mockImplementation(answer(preview));
            const wrapper = show();

            await row(wrapper, 'Broccoli').find('[data-test="pick"]').trigger('click');
            await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
            await row(wrapper, 'Broccoli').find('[data-test="weighing-cooked"]').trigger('click');
            await vi.advanceTimersByTimeAsync(200);
            expect(previewMock).not.toHaveBeenCalled();

            await settle();
            expect(previewMock).toHaveBeenCalledTimes(1);
            expect(previewInput().entries).toHaveLength(2);
            expect(previewInput().entries[0]).toMatchObject({ weighing: 'cooked' });
        });

        it('FOOD-030 browsing from the Nutrition tab asks for nothing', async () => {
            const wrapper = show({ payload: curated });

            await settle();

            expect(previewMock).not.toHaveBeenCalled();
            expect(wrapper.find('[data-test="basket"]').exists()).toBe(false);
        });
    });

    it('FOOD-028 tapping a food\'s name picks it too', async () => {
        const wrapper = show();

        await row(wrapper, 'Egg whites').find('[data-test="food-name"]').trigger('click');

        expect(wrapper.find('[data-test="basket"]').text()).toContain('Egg whites');
    });

    it('FOOD-028 a basket pick comes out again from its row or its minus button', async () => {
        const wrapper = show();

        await row(wrapper, 'Broccoli').find('[data-test="pick"]').trigger('click');
        expect(wrapper.find('[data-test="basket"]').text()).toContain('BroccoliRaw');
        await row(wrapper, 'Broccoli').find('[data-test="weighing-cooked"]').trigger('click');
        expect(wrapper.find('[data-test="basket"]').text()).toContain('BroccoliCooked');

        await wrapper.find('[data-test="basket"] button[aria-label="Remove Broccoli"]').trigger('click');
        expect(wrapper.find('[data-test="basket"]').exists()).toBe(false);
        expect(row(wrapper, 'Broccoli').find('[data-test="pick"]').attributes('aria-label')).toBe('Add Broccoli to meal');
    });

    it('FOOD-028 adds a recipe with the foods and loads a saved meal on its own write', async () => {
        const wrapper = show();

        await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
        await wrapper.find('select[aria-label="Show"]').setValue('recipes');
        await flushPromises();
        await wrapper.find('[data-test="collection-row"]').trigger('click');
        await wrapper.find('select[aria-label="Show"]').setValue('saved_meals');
        await flushPromises();
        await wrapper.find('[data-test="collection-row"]').trigger('click');

        expect(wrapper.find('[data-test="basket"]').findAll('li').map((li) => li.text())).toEqual(['Egg whites', 'Overnight oatsRecipe', 'Bulk dinnerSaved meal']);
        expect(wrapper.find('[data-test="collection-row"]').attributes('aria-pressed')).toBe('true');
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();

        expect(writes().map(([name]) => name)).toEqual(['meal_food_add', 'meal_saved_meal_load']);
        expect(writes()[0]![1]).toMatchObject({ entries: [{ food_id: '01JBX5F0000000000000000007' }, { recipe_id: '01JBX5R0000000000000000001' }] });
        expect(writes()[1]![1]).toEqual({ meal_id: MEAL, saved_meal_id: '01JBX5S0000000000000000001' });
        for (const [name, input] of writes()) expect(inputViolations(name, input)).toEqual([]);
        expect(back).toHaveBeenCalledOnce();
    });

    it('CAP-019 sends the same entry ids again after Add got no answer, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_food_add', 0, { reason: 'network' })).mockResolvedValueOnce({});
        const wrapper = show();

        await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();
        expect(back).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="confirm-error"]').exists()).toBe(true);
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();

        const ids = writes().map(([, input]) => (input as { entries: { id: string }[] }).entries[0]!.id);
        expect(ids[1]).toBe(ids[0]);
        expect(back).toHaveBeenCalledOnce();
    });

    it('FOOD-028 a saved meal that did not load stays in the basket, and Add sends only it again', async () => {
        writeMock
            .mockResolvedValueOnce({})
            .mockResolvedValueOnce({})
            .mockRejectedValueOnce(new CapabilityError('meal_saved_meal_load', 0, { reason: 'network' }))
            .mockResolvedValueOnce({});
        const twoMeals = structuredClone(curated);
        twoMeals.saved_meals.push({ saved_meal_id: '01JBX5S0000000000000000002', name: 'Cut lunch', detail: '2 foods' });
        readMock.mockResolvedValue(twoMeals);
        const wrapper = show({ payload: twoMeals, mealId: MEAL });

        await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
        await wrapper.find('select[aria-label="Show"]').setValue('saved_meals');
        await flushPromises();
        for (const meal of wrapper.findAll('[data-test="collection-row"]')) await meal.trigger('click');
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();

        expect(writes().map(([name, input]) => [name, input.saved_meal_id])).toEqual([
            ['meal_food_add', undefined],
            ['meal_saved_meal_load', '01JBX5S0000000000000000001'],
            ['meal_saved_meal_load', '01JBX5S0000000000000000002'],
        ]);
        expect(back).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="confirm-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="basket"]').findAll('li').map((li) => li.text())).toEqual(['Cut lunchSaved meal']);

        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();

        expect(writes().slice(3)).toEqual([['meal_saved_meal_load', { meal_id: MEAL, saved_meal_id: '01JBX5S0000000000000000002' }]]);
        expect(back).toHaveBeenCalledOnce();
    });

    it('FOOD-028 the whole Pantry waits while Add runs, so no pick, weighing or Cancel changes what was sent', async () => {
        let finish: (value: unknown) => void = () => {};
        writeMock.mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)));
        const wrapper = show();

        await row(wrapper, 'Broccoli').find('[data-test="pick"]').trigger('click');
        expect(wrapper.find('[data-test="pantry-content"]').attributes('inert')).toBeUndefined();
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();

        const content = wrapper.find('[data-test="pantry-content"]');
        expect(content.attributes('inert')).toBeDefined();
        for (const control of ['[data-test="cancel"]', '[data-test="weighing-cooked"]', '[data-test="pick"]', '[role="tablist"]']) {
            expect(content.find(control).exists(), control).toBe(true);
        }
        // The basket stands at the foot with Add (FOOD-030), and waits with the rest.
        expect(wrapper.find('[data-test="basket"]').attributes('inert')).toBeDefined();
        expect(wrapper.find('[data-test="confirm"]').attributes('disabled')).toBeDefined();
        finish({});
        await flushPromises();
        expect(wrapper.find('[data-test="pantry-content"]').attributes('inert')).toBeUndefined();
        await flushPromises();
        expect(back).toHaveBeenCalledOnce();
    });

    it('FOOD-028 NAV-002 a swipe back during Add still adds every pick, and does not go back again', async () => {
        let finish: (value: unknown) => void = () => {};
        writeMock.mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)));
        const wrapper = show();

        await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
        await wrapper.find('select[aria-label="Show"]').setValue('saved_meals');
        await flushPromises();
        await wrapper.find('[data-test="collection-row"]').trigger('click');
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();
        // The edge swipe pops the Pantry from outside it.
        wrapper.unmount();
        finish({});
        await flushPromises();

        expect(writes().map(([name]) => name)).toEqual(['meal_food_add', 'meal_saved_meal_load']);
        expect(back).not.toHaveBeenCalled();
    });

    it('NAV-002 an Add that finishes after the user opened another tab or screen does not go back', async () => {
        let finish: (value: unknown) => void = () => {};
        writeMock.mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)));
        const wrapper = show();

        await row(wrapper, 'Egg whites').find('[data-test="pick"]').trigger('click');
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();
        currentRoute.value = { fullPath: '/today' };
        finish({});
        await flushPromises();

        expect(back).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="basket"]').exists()).toBe(false);
    });

    it('FOOD-028 another tab during Add still loads every saved meal it confirmed', async () => {
        let finish: (value: unknown) => void = () => {};
        writeMock.mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)));
        const twoMeals = structuredClone(curated);
        twoMeals.saved_meals.push({ saved_meal_id: '01JBX5S0000000000000000002', name: 'Cut lunch', detail: '2 foods' });
        readMock.mockResolvedValue(twoMeals);
        const wrapper = show({ payload: twoMeals, mealId: MEAL });

        await wrapper.find('select[aria-label="Show"]').setValue('saved_meals');
        await flushPromises();
        for (const meal of wrapper.findAll('[data-test="collection-row"]')) await meal.trigger('click');
        await wrapper.find('[data-test="confirm"]').trigger('click');
        await flushPromises();
        currentRoute.value = { fullPath: '/today' };
        finish({});
        await flushPromises();

        expect(writes().map(([name, input]) => [name, input.saved_meal_id])).toEqual([
            ['meal_saved_meal_load', '01JBX5S0000000000000000001'],
            ['meal_saved_meal_load', '01JBX5S0000000000000000002'],
        ]);
        expect(wrapper.find('[data-test="basket"]').exists()).toBe(false);
        expect(back).not.toHaveBeenCalled();
    });

    it('FOOD-025 the source hint goes with the source chip when a collection shows', async () => {
        const wrapper = show();

        await wrapper.find('select[aria-label="Source filter"]').setValue('recents');
        await flushPromises();
        await wrapper.find('select[aria-label="Show"]').setValue('recipes');
        await flushPromises();

        expect(wrapper.text()).not.toContain('The source filter narrows search results.');
    });

    it('FOOD-006 switches a food with a cooked value between raw and cooked numbers', async () => {
        const wrapper = show();

        const breast = row(wrapper, 'Chicken breast');
        expect(breast.find('[data-test="nutrition"]').text()).toBe('120 kcal · P 22.5 · F 2.6 · C 0 · per 100 g');
        expect(row(wrapper, 'Egg whites').find('[data-test="weighing-raw"]').exists()).toBe(false);
        await breast.find('[data-test="weighing-cooked"]').trigger('click');

        expect(row(wrapper, 'Chicken breast').find('[data-test="nutrition"]').text()).toBe('165 kcal · P 31 · F 3.6 · C 0 · per 100 g');
        expect(row(wrapper, 'Chicken breast').find('[data-test="weighing-cooked"]').attributes('aria-pressed')).toBe('true');
    });

    it('FOOD-009 switches the amounts between g and oz', async () => {
        const wrapper = show();

        await wrapper.find('[data-test="unit-oz"]').trigger('click');
        await flushPromises();

        expect(lastRead()).toMatchObject({ unit: 'oz' });
        expect(wrapper.find('[data-test="unit-oz"]').attributes('aria-pressed')).toBe('true');
    });

    it('FOOD-028 marks a food that is already in the meal', () => {
        const inMeal = structuredClone(curated);
        inMeal.sections[1]!.rows[0]!.is_in_meal = true;
        const wrapper = show({ payload: inMeal, mealId: MEAL });

        expect(row(wrapper, 'Broccoli').text()).toContain('In meal');
        expect(row(wrapper, 'Egg whites').text()).not.toContain('In meal');
    });

    it('FOOD-062 offers a custom food named after the search', () => {
        const wrapper = show({ payload: search, mealId: MEAL });

        const link = wrapper.findAllComponents(RouterLinkStub).find((l) => l.text() === "Can't find it? Create a custom food");
        expect(link!.props('to')).toEqual({ path: '/nutrition/custom-foods/new', query: { name: 'chicken' } });
    });

    it('NAV-001 browsing from the Nutrition tab has Done and no basket', async () => {
        const wrapper = show({ payload: curated });

        expect(wrapper.find('[data-test="cancel"]').text()).toBe('Done');
        expect(wrapper.find('[data-test="confirm"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="pick"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="favorite"]').exists()).toBe(true);
        await wrapper.find('[data-test="cancel"]').trigger('click');
        expect(back).toHaveBeenCalledOnce();
    });

    it('NAV-001 browsing recipes and saved meals picks nothing', async () => {
        const wrapper = show({ payload: curated });

        for (const collection of ['recipes', 'saved_meals']) {
            await wrapper.find('select[aria-label="Show"]').setValue(collection);
            await flushPromises();
            await wrapper.find('[data-test="collection-row"]').trigger('click');
        }

        expect(wrapper.find('[data-test="basket"]').exists()).toBe(false);
    });

    it('FOOD-020 says when a list has nothing in it', () => {
        const wrapper = show({ payload: empty });

        expect(wrapper.find('[data-test="empty"]').text()).toBe('No custom foods yet.');
        expect(wrapper.find('[data-test="food-row"]').exists()).toBe(false);
    });

    it('PWA-024 shows a skeleton while the Pantry loads', async () => {
        readMock.mockReturnValue(new Promise(() => {}));
        const wrapper = show({ mealId: MEAL });
        await flushPromises();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(lastRead()).toEqual({
            query: '',
            tab: 'curated',
            collection: null,
            source: null,
            group: null,
            unit: 'g',
            meal_id: MEAL,
            expanded_groups: [],
        });
        expect(inputViolations('food_search', lastRead())).toEqual([]);
    });

    it('OFF-003 says why the Pantry did not load, and Try again reads it again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('food_search', 500, { ok: false }));
        const wrapper = show({ mealId: MEAL });
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('The foods could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.findAll('[data-test="food-row"]')).toHaveLength(3);
    });

    it('OFF-008 dims the server\'s numbers while a write waits to sync', () => {
        const wrapper = show({ payload: curated, mealId: MEAL, pending: true });

        const nutrition = row(wrapper, 'Chicken breast').find('[data-test="nutrition"]');
        expect(nutrition.find('[data-value]').classes()).toContain('opacity-60');
        expect(nutrition.text()).toContain('Waiting to sync');
        expect(show({ payload: curated }).text()).not.toContain('Waiting to sync');
    });
});

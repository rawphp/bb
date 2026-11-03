import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { read, writeNow } from '@/app/call';
import type { FoodSearchInput, FoodSearchOutput } from '@/contracts/food_search';
import { screenFixture } from '@/testing/schemas';
import PantryView from './PantryView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
const back = vi.fn();
const currentRoute = { value: { fullPath: '/nutrition/recipes/new' } };
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ back, currentRoute }) }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const search = screenFixture<FoodSearchOutput>('food_search');
const curated = screenFixture<FoodSearchOutput>('food_search.curated');

type Purpose = 'recipeIngredients' | 'recipeMatch';

function show(props: Record<string, unknown>) {
    return mount(PantryView, { props, attachTo: document.body, global: { stubs: { RouterLink: RouterLinkStub, teleport: true } } });
}

function row(wrapper: ReturnType<typeof show>, name: string) {
    const found = wrapper.findAll('[data-test="food-row"]').find((r) => r.find('[data-test="food-name"]').text() === name);
    if (!found) throw new Error(`No row for ${name}`);
    return found;
}

async function pick(wrapper: ReturnType<typeof show>, name: string): Promise<void> {
    await row(wrapper, name).find('[data-test="pick"]').trigger('click');
}

describe('PantryView for a recipe', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(curated);
        writeMock.mockReset().mockResolvedValue({});
        back.mockReset();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    describe.each<Purpose>(['recipeIngredients', 'recipeMatch'])('%s', (purpose) => {
        it('RCP-001 RCP-003 Confirm emits the picked food and sends no meal write', async () => {
            const wrapper = show({ payload: curated, purpose });

            await pick(wrapper, 'Egg whites');
            await wrapper.find('[data-test="confirm"]').trigger('click');
            await flushPromises();

            expect(wrapper.emitted('picked')).toEqual([[[{ food_id: '01JBX5F0000000000000000007', name: 'Egg whites' }]]]);
            expect(writeMock).not.toHaveBeenCalled();
            expect(back).not.toHaveBeenCalled();
        });

        it('RCP-001 RCP-003 Cancel emits cancel and does not go back', async () => {
            const wrapper = show({ payload: curated, purpose });

            await wrapper.find('[data-test="cancel"]').trigger('click');

            expect(wrapper.find('[data-test="cancel"]').text()).toBe('Cancel');
            expect(wrapper.emitted('cancel')).toHaveLength(1);
            expect(back).not.toHaveBeenCalled();
        });

        it('RCP-001 RCP-003 hides Recipes, Saved Meals and Already in meal', async () => {
            const wrapper = show({ payload: search, purpose });

            expect(wrapper.find('select[aria-label="Show"]').exists()).toBe(false);
            expect(wrapper.find('[data-test="already-in-meal"]').exists()).toBe(false);
            expect(wrapper.text()).not.toContain('Already in meal');
        });

        it('RCP-001 RCP-003 searches foods for no meal, and its rows add to the recipe', async () => {
            const wrapper = show({ purpose });
            await flushPromises();

            expect((readMock.mock.calls.at(-1)![1] as FoodSearchInput).meal_id).toBeNull();
            expect(row(wrapper, 'Egg whites').find('[data-test="pick"]').attributes('aria-label')).toBe('Add Egg whites to recipe');
        });

        it('RCP-001 RCP-003 the basket says it adds to the recipe', async () => {
            const wrapper = show({ payload: curated, purpose });

            await pick(wrapper, 'Broccoli');

            expect(wrapper.find('[data-test="basket"] h2').text()).toBe('Adding to recipe');
        });
    });

    it('RCP-001 recipeIngredients takes several foods, and the button counts them', async () => {
        const wrapper = show({ payload: curated, purpose: 'recipeIngredients' });
        const confirm = () => wrapper.find('[data-test="confirm"]');

        expect(confirm().text()).toBe('Add to recipe');
        expect(confirm().attributes('disabled')).toBeDefined();

        await pick(wrapper, 'Chicken breast');
        await pick(wrapper, 'Broccoli');

        expect(confirm().text()).toBe('Add 2 to recipe');
        await confirm().trigger('click');
        expect(wrapper.emitted('picked')).toEqual([
            [
                [
                    { food_id: '01JBX5F0000000000000000002', name: 'Chicken breast' },
                    { food_id: '01JBX5F0000000000000000008', name: 'Broccoli' },
                ],
            ],
        ]);
    });

    it('RCP-003 recipeMatch opens searching the item\'s name and holds one food, a new pick replacing the old', async () => {
        const wrapper = show({ purpose: 'recipeMatch', search: 'house sauce' });
        await flushPromises();
        const confirm = () => wrapper.find('[data-test="confirm"]');

        expect(wrapper.find<HTMLInputElement>('input[type="search"]').element.value).toBe('house sauce');
        expect((readMock.mock.calls.at(-1)![1] as FoodSearchInput).query).toBe('house sauce');
        expect(confirm().text()).toBe('Use food');

        await pick(wrapper, 'Chicken breast');
        await pick(wrapper, 'Broccoli');

        expect(wrapper.findAll('[data-test="basket"] li').map((li) => li.text())).toEqual([expect.stringContaining('Broccoli')]);
        expect(confirm().text()).toBe('Use selected food');
        await confirm().trigger('click');
        expect(wrapper.emitted('picked')).toEqual([[[{ food_id: '01JBX5F0000000000000000008', name: 'Broccoli' }]]]);
    });
});

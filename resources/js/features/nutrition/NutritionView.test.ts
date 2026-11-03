import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { NutritionShowOutput } from '@/contracts/nutrition_show';
import { screenFixture } from '@/testing/schemas';
import NutritionView from './NutritionView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const content = screenFixture<NutritionShowOutput>('nutrition_show');
const empty = screenFixture<NutritionShowOutput>('nutrition_show.empty');

function show(props: { payload?: NutritionShowOutput; pending?: boolean } = {}): VueWrapper {
    return mount(NutritionView, { props, global: { stubs: { RouterLink: RouterLinkStub } } });
}

function links(view: VueWrapper): unknown[] {
    return view.findAllComponents(RouterLinkStub).map((link) => link.props('to'));
}

describe('NutritionView', () => {
    beforeEach(() => readMock.mockReset());

    it("NAV-001 lists today's meals with their time and planned against target calories, without calling the server", () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Nutrition');
        const meals = view.findAll('[data-test="meal"]');
        expect(meals.map((meal) => meal.text())).toEqual([
            expect.stringContaining('Breakfast'),
            expect.stringContaining('Lunch'),
            expect.stringContaining('Dinner'),
            expect.stringContaining('Meal 4'),
        ]);
        expect(meals[0]!.text()).toContain('7:30 AM');
        expect(meals[2]!.text()).toContain('550 / 1040 kcal');
        expect(meals[3]!.text()).not.toContain('AM');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('NAV-002 each meal opens the meal screen Today opens', () => {
        const view = show({ payload: content });

        expect(links(view).filter((to) => String(to).startsWith('/meals/'))).toEqual(content.meals.map((meal) => `/meals/${meal.meal_id}`));
    });

    it("MEAL-047 shows each meal's check-in state as an icon named by the server's words", () => {
        const view = show({ payload: content });

        const icons = view.findAll('[data-test="check-in"]');
        expect(icons.map((icon) => icon.attributes('aria-label'))).toEqual(['Checked in', 'Checked in under target', 'Not checked in', 'Not checked in']);
        expect(icons[1]!.classes()).toContain('text-tone-warning');
    });

    it('NAV-001 links the Pantry, Saved meals, Recipes and the Shopping list', () => {
        const view = show({ payload: content });

        expect(links(view).filter((to) => !String(to).startsWith('/meals/'))).toEqual(['/nutrition/pantry', '/saved-meals', '/nutrition/recipes', '/nutrition/shopping']);
    });

    it("NAV-001 shows the server's empty line on a day with no meals, and keeps its links", () => {
        const view = show({ payload: empty });

        expect(view.findAll('[data-test="meal"]')).toHaveLength(0);
        expect(view.get('[data-test="empty"]').text()).toBe('No meals planned today.');
        expect(links(view)).toHaveLength(4);
    });

    it("CAP-004 shows a skeleton while it reads nutrition_show, then today's meals", async () => {
        readMock.mockResolvedValueOnce(content);
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(links(view)).toHaveLength(4);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('nutrition_show', {}, expect.any(Function), expect.any(Function));
        expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(view.text()).toContain('Dinner');
    });

    it("CAP-004 says why today's meals did not load, and Try again reads them again", async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('nutrition_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain("Today's meals could not be loaded.");
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.text()).toContain('Breakfast');
    });

    it("OFF-008 marks each meal's calories as waiting to sync", () => {
        const view = show({ payload: content, pending: true });

        expect(view.findAll('[data-test="meal"]').every((meal) => meal.text().includes('Waiting to sync'))).toBe(true);
        expect(view.get('[data-test="meal"] [data-value]').classes()).toContain('opacity-60');
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        expect(show({ payload: content }).text()).not.toContain('Waiting to sync');
    });
});

import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { CapabilityError, read } from '@/app/call';
import type { NutritionShowOutput } from '@/contracts/nutrition_show';
import type { WeekShowOutput } from '@/contracts/week_show';
import ProgressBar from '@/design/ProgressBar.vue';
import WeekStrip from '@/design/WeekStrip.vue';
import { screenFixture } from '@/testing/schemas';
import NutritionView from './NutritionView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const content = screenFixture<NutritionShowOutput>('nutrition_show');
const empty = screenFixture<NutritionShowOutput>('nutrition_show.empty');
const week = screenFixture<WeekShowOutput>('week_show');

let router: Router;

type Props = { payload?: NutritionShowOutput; week?: WeekShowOutput; date?: string; pending?: boolean };

function show(props: Props = {}): VueWrapper {
    router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    void router.push('/nutrition');
    return mount(NutritionView, { props: { week, ...props }, global: { plugins: [router], stubs: { RouterLink: RouterLinkStub } } });
}

// Reads answer from the fixtures by name, so the day and its week can arrive in either order.
function serve(day: NutritionShowOutput | Error = content): void {
    readMock.mockImplementation(async (name) => {
        if (name === 'week_show') return week;
        if (day instanceof Error) throw day;
        return day;
    });
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
        expect(meals[2]!.text()).toContain('550/1040');
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
        serve();
        const view = show({ week: undefined });

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(links(view)).toHaveLength(4);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('nutrition_show', {}, expect.any(Function), expect.any(Function));
        expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(view.text()).toContain('Dinner');
    });

    it("CAP-004 says why today's meals did not load, and Try again reads them again", async () => {
        let fail = true;
        readMock.mockImplementation(async (name) => {
            if (name === 'week_show') return week;
            if (fail) {
                fail = false;
                throw new CapabilityError('nutrition_show', 500, null);
            }
            return content;
        });
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain("Today's meals could not be loaded.");
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.text()).toContain('Breakfast');
    });

    it("OFF-008 marks each meal's bars as waiting to sync", () => {
        const view = show({ payload: content, pending: true });

        expect(view.findAll('[data-test="meal"]').every((meal) => meal.text().includes('Waiting to sync'))).toBe(true);
        expect(view.get('[data-test="meal"] [data-test="bars"]').element.parentElement!.classList).toContain('opacity-50');
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        expect(show({ payload: content }).text()).not.toContain('Waiting to sync');
    });

    it('NAV-005 UI-003 each meal shows four macro bars, its planned food against its target, from the server', () => {
        const view = show({ payload: content });

        // Lunch, the second meal: its bars are the fifth to the eighth.
        const bars = view.findAllComponents(ProgressBar).slice(4, 8);
        expect(bars.map((bar) => [bar.props('label'), bar.props('valueText'), bar.props('fraction'), bar.props('tint')])).toEqual([
            ['Cal', '610/700', 0.871, 'calories'],
            ['P', '44/51', 0.863, 'protein'],
            ['F', '18/21', 0.857, 'fat'],
            ['C', '66/82', 0.805, 'carbs'],
        ]);
        expect(bars.map((bar) => bar.props('title'))).toEqual(['Calories', 'Protein', 'Fat', 'Carbs']);
    });

    it('NAV-005 UI-001 shows the week strip, and a picked day opens its meals at /nutrition/<date>', async () => {
        const view = show({ payload: content });
        await flushPromises();

        const strip = view.getComponent(WeekStrip);
        expect(strip.props('days')).toEqual(week.days);
        strip.vm.$emit('select', '2026-09-24');
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe('/nutrition/2026-09-24');
    });

    it('NAV-005 a swipe on the strip opens the week before or after', async () => {
        const view = show({ payload: content });
        await flushPromises();

        view.getComponent(WeekStrip).vm.$emit('swipe', 1);
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe(`/nutrition/${week.next_week}`);

        view.getComponent(WeekStrip).vm.$emit('swipe', -1);
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe(`/nutrition/${week.previous_week}`);
    });

    it("NAV-005 reads the named day's meals and its week, and heads the list with the server's day", async () => {
        serve({ ...content, date_text: 'Thursday, Sep 24' });
        const view = show({ date: '2026-09-24', week: undefined });
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('nutrition_show', { date: '2026-09-24' }, expect.any(Function), expect.any(Function));
        expect(readMock).toHaveBeenCalledWith('week_show', { date: '2026-09-24' }, expect.any(Function), expect.any(Function));
        expect(view.get('#nutrition-day').text()).toBe('Thursday, Sep 24');
    });

    it('NAV-005 shows placeholder days while the week loads, and why it did not', async () => {
        readMock.mockImplementation(async (name) => {
            if (name === 'week_show') throw new CapabilityError('week_show', 500, null);
            return content;
        });
        const view = show({ week: undefined });

        expect(view.getComponent(WeekStrip).findAll('[data-placeholder]')).toHaveLength(7);
        await flushPromises();
        expect(view.getComponent(WeekStrip).props('error')).toBeTruthy();
    });
});

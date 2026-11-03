import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { RecipesShowInput, RecipesShowOutput } from '@/contracts/recipes_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import RecipesView from './RecipesView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
const push = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ push, back: vi.fn() }) }));

const readMock = vi.mocked(read);
const content = screenFixture<RecipesShowOutput>('recipes_show');
const empty = screenFixture<RecipesShowOutput>('recipes_show.empty');

let wrapper: VueWrapper | undefined;

function show(props: { payload?: RecipesShowOutput; pending?: boolean } = {}): VueWrapper {
    wrapper = mount(RecipesView, { props, attachTo: document.body, global: { stubs: { RouterLink: RouterLinkStub } } });
    return wrapper;
}

function lastInput(): RecipesShowInput {
    return readMock.mock.calls.at(-1)![1] as RecipesShowInput;
}

function dietButton(title: string): HTMLButtonElement {
    return [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] [data-test="diet"]')].find((b) => b.textContent?.trim() === title)!;
}

describe('RecipesView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        push.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('RCP-020 lists each recipe with its meal type and the server\'s macros per serving, without calling the server', () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Recipes');
        const rows = view.findAll('[data-test="recipe-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]!.text()).toContain('Overnight oats');
        expect(rows[0]!.text()).toContain('Breakfast');
        expect(rows[0]!.text()).toContain('330 kcal · P 28 · F 3 · C 47 per serving');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('RCP-001 a recipe opens its editor', () => {
        const view = show({ payload: content });

        const links = view.findAllComponents(RouterLinkStub);
        expect(links.map((link) => link.props('to'))).toContain('/nutrition/recipes/01JBX5R0000000000000000001');
    });

    it('RCP-020 shows the server\'s empty line when nothing matches', () => {
        const view = show({ payload: empty });

        expect(view.findAll('[data-test="recipe-row"]')).toHaveLength(0);
        expect(view.get('[data-test="empty"]').text()).toBe('No recipes yet. Create one, or import one from a web page.');
    });

    it('CAP-004 shows a skeleton while it reads recipes_show, then the list', async () => {
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('recipes_show', { segment: 'mine', meal_type: null, diets: [] }, expect.any(Function), expect.any(Function));
        expect(inputViolations('recipes_show', lastInput())).toEqual([]);
        expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(view.text()).toContain('Turkey chili');
    });

    it('CAP-004 says why the list did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('recipes_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('The recipes could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.text()).toContain('Overnight oats');
    });

    it('OFF-008 marks the server\'s macros as waiting to sync', () => {
        const view = show({ payload: content, pending: true });

        expect(view.findAll('[data-test="recipe-row"]').every((row) => row.text().includes('Waiting to sync'))).toBe(true);
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        expect(show({ payload: content }).text()).not.toContain('Waiting to sync');
    });

    it('RCP-020 opens with no diet filter on, and the filters turned on are sent in diets', async () => {
        const view = show();
        await flushPromises();
        expect(lastInput().diets).toEqual([]);
        expect(view.get('[data-test="diet-chip"]').text()).toBe('Diet');

        await view.get('[data-test="diet-chip"]').trigger('click');
        expect([...document.body.querySelectorAll('[role="dialog"] [data-test="diet"]')].map((b) => b.textContent?.trim())).toEqual([
            'Dairy free',
            'Gluten free',
            'Grain free',
            'Low FODMAP',
            'Ovo-lacto vegetarian',
            'Paleo',
            'Vegan',
        ]);
        expect(dietButton('Vegan').getAttribute('aria-pressed')).toBe('false');

        dietButton('Vegan').click();
        await flushPromises();
        dietButton('Gluten free').click();
        await flushPromises();

        expect(lastInput()).toEqual({ segment: 'mine', meal_type: null, diets: ['gluten_free', 'vegan'] });
        expect(inputViolations('recipes_show', lastInput())).toEqual([]);
        expect(view.get('[data-test="diet-chip"]').text()).toBe('Diet · 2');

        dietButton('Vegan').click();
        await flushPromises();
        expect(lastInput().diets).toEqual(['gluten_free']);
    });

    it('RCP-020 the Library segment and a meal type chip narrow the list', async () => {
        const view = show();
        await flushPromises();

        const segments = view.findAll('[role="tab"]');
        expect(segments.map((s) => s.text())).toEqual(['My recipes', 'Library']);
        await segments[1]!.trigger('click');
        await flushPromises();
        expect(lastInput()).toEqual({ segment: 'library', meal_type: null, diets: [] });

        const chips = view.findAll('[data-test="meal-type"]');
        expect(chips.map((c) => c.text())).toEqual(['All', 'Breakfast', 'Lunch', 'Dinner', 'Snack']);
        await chips[3]!.trigger('click');
        await flushPromises();
        expect(lastInput()).toEqual({ segment: 'library', meal_type: 'dinner', diets: [] });
        expect(chips[3]!.attributes('aria-pressed')).toBe('true');
        expect(inputViolations('recipes_show', lastInput())).toEqual([]);

        await chips[0]!.trigger('click');
        await flushPromises();
        expect(lastInput().meal_type).toBeNull();
    });

    it('RCP-001 RCP-003 RCP-010 the + menu makes a new recipe, imports one or generates one', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Add a recipe"]').trigger('click');
        const items = view.findAll('[role="menuitem"]');
        expect(items.map((item) => item.text())).toEqual(['New recipe', 'Import from URL', 'Generate with AI']);

        for (const [index, path] of ['/nutrition/recipes/new', '/nutrition/recipes/import', '/nutrition/recipes/generate'].entries()) {
            if (!view.find('[role="menu"]').exists()) await view.get('button[aria-label="Add a recipe"]').trigger('click');
            await view.findAll('[role="menuitem"]')[index]!.trigger('click');
            expect(push).toHaveBeenLastCalledWith(path);
        }
    });
});

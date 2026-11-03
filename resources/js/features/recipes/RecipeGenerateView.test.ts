import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AI_TIMEOUT_MS, CapabilityError, read, readNow } from '@/app/call';
import type { RecipeGenerateOutput } from '@/contracts/recipe_generate';
import { inputViolations, screenFixture } from '@/testing/schemas';
import RecipeDraftView from './RecipeDraftView.vue';
import RecipeGenerateView from './RecipeGenerateView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), readNow: vi.fn(), writeNow: vi.fn() }));
const back = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ back, push: vi.fn() }) }));

const freshMock = vi.mocked(readNow);
const draft = screenFixture<RecipeGenerateOutput>('recipe_generate');

let wrapper: VueWrapper | undefined;

function show(props: Record<string, unknown> = {}): VueWrapper {
    wrapper = mount(RecipeGenerateView, { props, attachTo: document.body, global: { stubs: { teleport: true, PantryView: true } } });
    return wrapper;
}

function field(view: VueWrapper, label: string) {
    return view.get<HTMLInputElement | HTMLTextAreaElement>(`[aria-label="${label}"]`);
}

function generate(view: VueWrapper) {
    return view.get('[data-test="generate"]');
}

async function fillMacros(view: VueWrapper): Promise<void> {
    await field(view, 'Protein (g)').setValue('40');
    await field(view, 'Fat (g)').setValue('15');
    await field(view, 'Carbs (g)').setValue('60,5');
}

describe('RecipeGenerateView', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset();
        freshMock.mockReset().mockResolvedValue(draft);
        back.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('RCP-010 asks for macros per serving, meal type, complexity, ingredients and preferences, and reads nothing', () => {
        const view = show();

        expect(view.get('h1').text()).toBe('Generate with AI');
        for (const label of ['Protein (g)', 'Fat (g)', 'Carbs (g)', 'Calories (optional)', 'Ingredients to use', 'Preferences']) {
            expect(view.find(`[aria-label="${label}"]`).exists()).toBe(true);
        }
        const meal = view.findAll('[role="radiogroup"][aria-label="Meal type"] [role="radio"]');
        expect(meal.map((b) => b.text())).toEqual(['Breakfast', 'Lunch', 'Dinner', 'Snack']);
        expect(meal[2]!.attributes('aria-checked')).toBe('true');
        const complexity = view.findAll('[role="radiogroup"][aria-label="Complexity"] [role="radio"]');
        expect(complexity.map((b) => b.text())).toEqual(['Simple', 'Moderate', 'Elaborate']);
        expect(complexity[0]!.attributes('aria-checked')).toBe('true');
        expect(freshMock).not.toHaveBeenCalled();
    });

    it('RCP-010 shows no diet note: recipe_generate applies the profile\'s filters', () => {
        const view = show();

        expect(view.text()).not.toContain('These come from your profile');
        expect(view.text()).not.toContain('Diet filters apply');
    });

    it('RCP-010 Generate waits for protein, fat and carbs', async () => {
        const view = show();

        expect(generate(view).attributes('disabled')).toBeDefined();
        await field(view, 'Protein (g)').setValue('40');
        await field(view, 'Fat (g)').setValue('15');
        expect(generate(view).attributes('disabled')).toBeDefined();
        await field(view, 'Carbs (g)').setValue('abc');
        expect(generate(view).attributes('disabled')).toBeDefined();
        await field(view, 'Carbs (g)').setValue('60');
        expect(generate(view).attributes('disabled')).toBeUndefined();
    });

    it('RCP-010 Generate sends recipe_generate with what was typed, then shows the draft to confirm', async () => {
        const view = show();

        await fillMacros(view);
        await view.findAll('[role="radiogroup"][aria-label="Meal type"] [role="radio"]')[1]!.trigger('click');
        await view.findAll('[role="radiogroup"][aria-label="Complexity"] [role="radio"]')[2]!.trigger('click');
        await field(view, 'Ingredients to use').setValue('chicken breast, rice,\n broccoli ,,');
        await field(view, 'Preferences').setValue('  Mexican  ');
        await generate(view).trigger('click');
        await flushPromises();

        const [name, input] = freshMock.mock.calls[0]!;
        expect(name).toBe('recipe_generate');
        expect(input).toEqual({
            protein: 40,
            fat: 15,
            carbs: 60.5,
            kcal: null,
            meal_type: 'lunch',
            complexity: 'elaborate',
            ingredients: ['chicken breast', 'rice', 'broccoli'],
            preferences: 'Mexican',
        });
        expect(inputViolations('recipe_generate', input)).toEqual([]);
        expect(freshMock.mock.calls[0]![2]).toEqual({ timeoutMs: AI_TIMEOUT_MS });
        expect(view.getComponent(RecipeDraftView).props('draft')).toEqual(draft);
    });

    it('RCP-010 sends the calories when typed, and no preferences when left blank', async () => {
        const view = show();

        await fillMacros(view);
        await field(view, 'Calories (optional)').setValue('600');
        await generate(view).trigger('click');
        await flushPromises();

        const input = freshMock.mock.calls[0]![1] as Record<string, unknown>;
        expect(input.kcal).toBe(600);
        expect(input.preferences).toBeNull();
        expect(input.ingredients).toEqual([]);
        expect(inputViolations('recipe_generate', input)).toEqual([]);
    });

    it('RCP-010 says it is generating, and the form waits', async () => {
        freshMock.mockReturnValueOnce(new Promise(() => {}));
        const view = show();

        await fillMacros(view);
        await generate(view).trigger('click');

        expect(generate(view).text()).toBe('Generating…');
        expect(generate(view).attributes('disabled')).toBeDefined();
    });

    it('CAP-012 a refusal stays on the form with the server\'s words', async () => {
        freshMock.mockReset().mockRejectedValueOnce(new CapabilityError('recipe_generate', 422, { error: { message: 'Name up to 20 ingredients.' } }));
        const view = show();

        await fillMacros(view);
        await generate(view).trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Name up to 20 ingredients.');
        expect(view.findComponent(RecipeDraftView).exists()).toBe(false);
    });

    it('RCP-010 a generation past the deadline says it took too long, not that the device is offline', async () => {
        freshMock.mockReset().mockRejectedValueOnce(new CapabilityError('recipe_generate', 0, { reason: 'timeout' }));
        const view = show();

        await fillMacros(view);
        await generate(view).trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toBe('The recipe took too long. Try again.');
    });

    it('OFF-031 offline it says Generate needs a connection', async () => {
        freshMock.mockReset().mockRejectedValueOnce(new CapabilityError('recipe_generate', 0, null));
        const view = show();

        await fillMacros(view);
        await generate(view).trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('No connection. Try again when you are online.');
    });

    it('RCP-010 opened with a drafted recipe it shows the confirm step', () => {
        const view = show({ payload: draft });

        expect(view.getComponent(RecipeDraftView).props('draft')).toEqual(draft);
    });

    it('RCP-010 Cancel goes back', async () => {
        const view = show();

        await view.get('[data-test="cancel"]').trigger('click');

        expect(back).toHaveBeenCalledOnce();
    });
});

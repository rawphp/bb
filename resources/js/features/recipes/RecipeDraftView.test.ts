import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { read, writeNow } from '@/app/call';
import type { RecipeGenerateOutput } from '@/contracts/recipe_generate';
import type { RecipeImportOutput } from '@/contracts/recipe_import';
import { inputViolations, screenFixture } from '@/testing/schemas';
import RecipeDraftView from './RecipeDraftView.vue';
import RecipeEditorView from './RecipeEditorView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
const back = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ back, push: vi.fn() }) }));

const writeMock = vi.mocked(writeNow);
const generated = screenFixture<RecipeGenerateOutput>('recipe_generate');
const imported = screenFixture<RecipeImportOutput>('recipe_import');

let wrapper: VueWrapper | undefined;

function show(draft: RecipeGenerateOutput = generated): VueWrapper {
    wrapper = mount(RecipeDraftView, { props: { draft }, attachTo: document.body, global: { stubs: { teleport: true, PantryView: true } } });
    return wrapper;
}

function items(view: VueWrapper) {
    return view.findAll('[data-test="draft-item"]');
}

function continueButton(view: VueWrapper) {
    return view.get('[data-test="continue"]');
}

describe('RecipeDraftView', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset();
        writeMock.mockReset().mockResolvedValue({});
        back.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('RCP-003 RCP-010 shows the draft and each item with the food it matched, or No match', () => {
        const view = show();

        expect(view.get('h1').text()).toBe('Confirm ingredients');
        expect(view.text()).toContain('Chicken rice bowl');
        expect(view.text()).toContain('2 servings · 2 steps');
        const rows = items(view);
        expect(rows).toHaveLength(3);
        expect(rows[0]!.text()).toContain('Chicken breast');
        expect(rows[0]!.text()).toContain('From the recipe: chicken breast');
        expect(rows[0]!.get<HTMLInputElement>('input[aria-label="chicken breast grams"]').element.value).toBe('300');
        expect(rows[0]!.get('[data-test="change-food"]').text()).toBe('Change food');
        expect(rows[2]!.get('[data-test="no-match"]').text()).toContain('No match');
        expect(rows[2]!.get('[data-test="change-food"]').text()).toBe('Pick a food');
        expect(vi.mocked(read)).not.toHaveBeenCalled();
    });

    it('RCP-003 an import with one serving and no steps says so', () => {
        const view = show({ ...imported, servings: 1, steps: [] });

        expect(view.text()).toContain('1 serving · 0 steps');
    });

    it('RCP-003 Continue waits until every item has a food, and says which needs one', async () => {
        const view = show();

        expect(continueButton(view).attributes('disabled')).toBeDefined();
        expect(view.get('[data-test="blocked"]').text()).toBe('Pick a food for "house sauce", or remove it.');

        await view.get('button[aria-label="Remove house sauce"]').trigger('click');

        expect(items(view)).toHaveLength(2);
        expect(continueButton(view).attributes('disabled')).toBeUndefined();
        expect(view.find('[data-test="blocked"]').exists()).toBe(false);
    });

    it('RCP-003 Continue waits while no item is left', async () => {
        const view = show({ ...generated, items: [generated.items[0]!] });

        await view.get('button[aria-label="Remove chicken breast"]').trigger('click');

        expect(continueButton(view).attributes('disabled')).toBeDefined();
        expect(view.get('[data-test="blocked"]').text()).toBe('Keep at least one ingredient.');
    });

    it('RCP-003 Pick a food opens the Pantry in a sheet searching the item\'s name, and the picked food becomes its match', async () => {
        const view = show();

        await items(view)[2]!.get('[data-test="change-food"]').trigger('click');
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
        const pantry = view.findComponent({ name: 'PantryView' });
        expect(pantry.props('purpose')).toBe('recipeMatch');
        expect(pantry.props('search')).toBe('house sauce');

        pantry.vm.$emit('picked', [{ food_id: '01JBX5F0000000000000000099', name: 'Hot sauce' }]);
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
        expect(items(view)[2]!.text()).toContain('Hot sauce');
        expect(items(view)[2]!.find('[data-test="no-match"]').exists()).toBe(false);
        expect(items(view)[2]!.get('[data-test="change-food"]').text()).toBe('Change food');
        expect(continueButton(view).attributes('disabled')).toBeUndefined();
    });

    it('RCP-003 Cancel in the Pantry keeps the item\'s match', async () => {
        const view = show();

        await items(view)[0]!.get('[data-test="change-food"]').trigger('click');
        view.findComponent({ name: 'PantryView' }).vm.$emit('cancel');
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
        expect(items(view)[0]!.text()).toContain('Chicken breast');
    });

    it('RCP-003 RCP-010 Continue opens the editor with the draft as confirmed, and saving it sends recipe_save', async () => {
        const view = show();

        await items(view)[0]!.get('input[aria-label="chicken breast grams"]').setValue('320');
        await items(view)[2]!.get('[data-test="change-food"]').trigger('click');
        view.findComponent({ name: 'PantryView' }).vm.$emit('picked', [{ food_id: '01JBX5F0000000000000000099', name: 'Hot sauce' }]);
        await flushPromises();
        await continueButton(view).trigger('click');

        const editor = view.getComponent(RecipeEditorView);
        expect(editor.props('draft')).toEqual({
            name: 'Chicken rice bowl',
            servings: 2,
            meal_type: 'dinner',
            steps: ['Cook the rice.', 'Grill the chicken and slice it.'],
            ingredients: [
                { food_id: '01JBX5F0000000000000000002', name: 'Chicken breast', grams: 320 },
                { food_id: '01JBX5F0000000000000000021', name: 'Rice, jasmine', grams: 150 },
                { food_id: '01JBX5F0000000000000000099', name: 'Hot sauce', grams: 40 },
            ],
        });

        await editor.get('[data-test="save"]').trigger('click');
        await flushPromises();
        const [name, input] = writeMock.mock.calls.at(-1)!;
        expect(name).toBe('recipe_save');
        expect(inputViolations('recipe_save', input)).toEqual([]);
    });

    it('RCP-003 Back from the editor returns to the draft as it was', async () => {
        const view = show({ ...generated, items: generated.items.slice(0, 2) });

        await continueButton(view).trigger('click');
        await view.getComponent(RecipeEditorView).get('[data-test="back"]').trigger('click');

        expect(view.findComponent(RecipeEditorView).exists()).toBe(false);
        expect(items(view)).toHaveLength(2);
    });

    it('RCP-003 Cancel leaves the draft', async () => {
        const view = show();

        await view.get('[data-test="cancel"]').trigger('click');

        expect(back).toHaveBeenCalledOnce();
    });
});

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { RecipeSaveInput } from '@/contracts/recipe_save';
import type { RecipeShowOutput } from '@/contracts/recipe_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import RecipeEditorView from './RecipeEditorView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
const back = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ back, push: vi.fn() }) }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const saved = screenFixture<RecipeShowOutput>('recipe_show');
const library = screenFixture<RecipeShowOutput>('recipe_show.library');
const ID = saved.recipe_id;

let wrapper: VueWrapper | undefined;

function show(props: Record<string, unknown> = { payload: saved }): VueWrapper {
    wrapper = mount(RecipeEditorView, { props, attachTo: document.body, global: { stubs: { teleport: true, PantryView: true } } });
    return wrapper;
}

function field(view: VueWrapper, label: string) {
    return view.get<HTMLInputElement>(`[aria-label="${label}"]`);
}

function saveButton(view: VueWrapper) {
    return view.get('[data-test="save"]');
}

function lastWrite(): [string, Record<string, unknown>] {
    return writeMock.mock.calls.at(-1)!.slice(0, 2) as [string, Record<string, unknown>];
}

describe('RecipeEditorView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(saved);
        writeMock.mockReset().mockResolvedValue({});
        back.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('RCP-001 shows a saved recipe\'s name, servings, meal type, ingredients and steps, without calling the server', () => {
        const view = show();

        expect(view.get('h1').text()).toBe('Chicken burrito bowl');
        expect(field(view, 'Name').element.value).toBe('Chicken burrito bowl');
        expect(field(view, 'Servings').element.value).toBe('4');
        expect(view.get<HTMLSelectElement>('select[aria-label="Meal type"]').element.value).toBe('dinner');
        expect(view.findAll('[data-test="ingredient"]').map((row) => row.text())).toEqual([expect.stringContaining('Chicken breast'), expect.stringContaining('Black beans')]);
        expect(field(view, 'Chicken breast grams').element.value).toBe('600');
        expect(field(view, 'Step 1').element.value).toBe('Cook the rice.');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('RCP-001 shows the saved recipe\'s per-serving macros from recipe_show', () => {
        const view = show();

        expect(view.get('[data-test="per-serving"]').text()).toContain('Per serving');
        expect(view.get('[data-test="per-serving"]').text()).toContain('520 kcal · P 45 g · F 12 g · C 55 g');
    });

    it('RCP-001 a new recipe has no per-serving section and reads nothing', async () => {
        const view = show({});
        await flushPromises();

        expect(view.get('h1').text()).toBe('New recipe');
        expect(view.find('[data-test="per-serving"]').exists()).toBe(false);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-004 shows a skeleton while it reads recipe_show, then the recipe', async () => {
        const view = show({ recipeId: ID });

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('recipe_show', { recipe_id: ID }, expect.any(Function), expect.any(Function));
        expect(inputViolations('recipe_show', { recipe_id: ID })).toEqual([]);
        expect(field(view, 'Name').element.value).toBe('Chicken burrito bowl');
    });

    it('CAP-004 says why the recipe did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('recipe_show', 500, null)).mockResolvedValueOnce(saved);
        const view = show({ recipeId: ID });
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('The recipe could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(field(view, 'Name').element.value).toBe('Chicken burrito bowl');
    });

    it('OFF-008 marks the per-serving macros as waiting to sync', () => {
        expect(show({ payload: saved, pending: true }).get('[data-test="per-serving"]').text()).toContain('Waiting to sync');
        wrapper!.unmount();
        expect(show({ payload: saved }).get('[data-test="per-serving"]').text()).not.toContain('Waiting to sync');
    });

    it('RCP-020 a library recipe is read-only: its values and amounts, no Save and no Delete', () => {
        const view = show({ payload: library });

        expect(view.text()).toContain('Library recipe');
        expect(view.find('input').exists()).toBe(false);
        expect(view.find('[data-test="save"]').exists()).toBe(false);
        expect(view.find('[data-test="delete"]').exists()).toBe(false);
        expect(view.find('[data-test="add-ingredient"]').exists()).toBe(false);
        expect(view.text()).toContain(library.servings_text);
        expect(view.text()).toContain(library.ingredients[0]!.amount_text);
        expect(view.text()).toContain(library.steps[0] as string);
    });

    it('RCP-001 RCP-021 Save sends recipe_save for the recipe with what was typed, then goes back', async () => {
        const view = show();

        await field(view, 'Name').setValue('  Burrito bowl ');
        await field(view, 'Servings').setValue('3');
        await field(view, 'Chicken breast grams').setValue('650');
        await view.get('[data-test="cooked-Chicken breast"]').trigger('click');
        await field(view, 'Step 2').setValue('   ');
        await saveButton(view).trigger('click');
        await flushPromises();

        const [name, input] = lastWrite();
        expect(name).toBe('recipe_save');
        expect(input).toEqual({
            id: ID,
            name: 'Burrito bowl',
            servings: 3,
            meal_type: 'dinner',
            ingredients: [
                { food_id: '01JBX5F0000000000000000002', grams: 650, is_cooked: true },
                { food_id: '01JBX5F0000000000000000010', grams: 240, is_cooked: false },
            ],
            steps: ['Cook the rice.', 'Build the bowls.'],
        } satisfies RecipeSaveInput);
        expect(inputViolations('recipe_save', input)).toEqual([]);
        expect(back).toHaveBeenCalledOnce();
    });

    it('RCP-001 only a food with a cooked value can be weighed cooked', () => {
        const view = show();

        expect(view.find('[data-test="cooked-Chicken breast"]').exists()).toBe(true);
        expect(view.find('[data-test="cooked-Black beans"]').exists()).toBe(false);
    });

    it('CAP-012 a refused save stays with the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('recipe_save', 422, { error: { message: 'Servings must be more than 0 and at most 100.' } }));
        const view = show();

        await field(view, 'Servings').setValue('500');
        await saveButton(view).trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Servings must be more than 0 and at most 100.');
        expect(back).not.toHaveBeenCalled();
    });

    it('RCP-001 a new recipe saves under a new id once it has a name, a meal type and an ingredient', async () => {
        const view = show({});

        expect(saveButton(view).attributes('disabled')).toBeDefined();
        await field(view, 'Name').setValue('Protein oats');
        await view.get('select[aria-label="Meal type"]').setValue('breakfast');
        expect(saveButton(view).attributes('disabled')).toBeDefined();

        await view.get('[data-test="add-ingredient"]').trigger('click');
        const pantry = view.findComponent({ name: 'PantryView' });
        expect(pantry.props('purpose')).toBe('recipeIngredients');
        pantry.vm.$emit('picked', [{ food_id: '01JBX5F0000000000000000007', name: 'Egg whites' }]);
        await flushPromises();

        expect(saveButton(view).attributes('disabled')).toBeUndefined();
        await saveButton(view).trigger('click');
        await flushPromises();

        const [name, input] = lastWrite();
        expect(name).toBe('recipe_save');
        expect(input).toEqual({
            id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/),
            name: 'Protein oats',
            servings: 1,
            meal_type: 'breakfast',
            ingredients: [{ food_id: '01JBX5F0000000000000000007', grams: 100, is_cooked: false }],
            steps: [],
        });
        expect(inputViolations('recipe_save', input)).toEqual([]);
    });

    it('RCP-001 Add ingredient opens the Pantry in a sheet; each picked food joins at 100 g and what was typed stays', async () => {
        const view = show();

        await field(view, 'Name').setValue('Bigger bowl');
        await view.get('[data-test="add-ingredient"]').trigger('click');
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
        const pantry = view.findComponent({ name: 'PantryView' });
        expect(pantry.props('purpose')).toBe('recipeIngredients');

        pantry.vm.$emit('picked', [
            { food_id: '01JBX5F0000000000000000008', name: 'Broccoli' },
            { food_id: '01JBX5F0000000000000000002', name: 'Chicken breast' },
        ]);
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
        expect(field(view, 'Name').element.value).toBe('Bigger bowl');
        expect(view.findAll('[data-test="ingredient"]')).toHaveLength(3);
        expect(field(view, 'Broccoli grams').element.value).toBe('100');
        // A food already in the recipe stays as it is.
        expect(field(view, 'Chicken breast grams').element.value).toBe('600');
    });

    it('RCP-001 Cancel in the Pantry closes it and adds nothing', async () => {
        const view = show();

        await view.get('[data-test="add-ingredient"]').trigger('click');
        view.findComponent({ name: 'PantryView' }).vm.$emit('cancel');
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
        expect(view.findAll('[data-test="ingredient"]')).toHaveLength(2);
    });

    it('RCP-001 an ingredient and a step can be removed, and a step added', async () => {
        const view = show();

        await view.get('button[aria-label="Remove Black beans"]').trigger('click');
        await view.get('button[aria-label="Remove step 1"]').trigger('click');
        await view.get('[data-test="add-step"]').trigger('click');
        await field(view, 'Step 3').setValue('Serve.');
        await saveButton(view).trigger('click');
        await flushPromises();

        const [, input] = lastWrite() as [string, RecipeSaveInput];
        expect(input.ingredients.map((i) => i.food_id)).toEqual(['01JBX5F0000000000000000002']);
        expect(input.steps).toEqual(['Grill the chicken and slice it.', 'Build the bowls.', 'Serve.']);
    });

    it('RCP-001 Delete asks first, then sends recipe_delete and goes back', async () => {
        const view = show();

        await view.get('[data-test="delete"]').trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
        expect(document.body.querySelector('[role="dialog"]')!.textContent).toContain('Meals that already have it keep their copy.');

        document.body.querySelector<HTMLButtonElement>('[data-test="confirm-delete"]')!.click();
        await flushPromises();

        expect(lastWrite()).toEqual(['recipe_delete', { recipe_id: ID }]);
        expect(inputViolations('recipe_delete', lastWrite()[1])).toEqual([]);
        expect(back).toHaveBeenCalledOnce();
    });

    it('RCP-001 a recipe the server says cannot be deleted has no Delete', () => {
        expect(show({ payload: { ...saved, can_delete: false } }).find('[data-test="delete"]').exists()).toBe(false);
    });

    it('RCP-003 RCP-010 a confirmed draft opens unsaved: its values, no per-serving section, saved under a new id', async () => {
        const view = show({
            draft: {
                name: 'Chicken rice bowl',
                servings: 2,
                meal_type: null,
                steps: ['Cook the rice.'],
                ingredients: [{ food_id: '01JBX5F0000000000000000002', name: 'Chicken breast', grams: 300 }],
            },
        });

        expect(view.get('h1').text()).toBe('Chicken rice bowl');
        expect(view.find('[data-test="per-serving"]').exists()).toBe(false);
        expect(field(view, 'Chicken breast grams').element.value).toBe('300');
        expect(saveButton(view).attributes('disabled')).toBeDefined();

        await view.get('select[aria-label="Meal type"]').setValue('lunch');
        await saveButton(view).trigger('click');
        await flushPromises();

        const [name, input] = lastWrite();
        expect(name).toBe('recipe_save');
        expect(input).toEqual({
            id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/),
            name: 'Chicken rice bowl',
            servings: 2,
            meal_type: 'lunch',
            ingredients: [{ food_id: '01JBX5F0000000000000000002', grams: 300, is_cooked: false }],
            steps: ['Cook the rice.'],
        });
        expect(inputViolations('recipe_save', input)).toEqual([]);
        expect(back).toHaveBeenCalledOnce();
    });

    it('RCP-003 RCP-010 Back from a draft\'s editor returns to the draft', async () => {
        const view = show({ draft: { name: 'X', servings: 1, meal_type: null, steps: [], ingredients: [] } });

        await view.get('[data-test="back"]').trigger('click');

        expect(view.emitted('back')).toHaveLength(1);
        expect(back).not.toHaveBeenCalled();
    });
});

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { SavedMealsShowOutput } from '@/contracts/saved_meals_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import SavedMealRowView from './SavedMealRowView.vue';
import SavedMealsStatus from './SavedMealsStatus.vue';
import SavedMealsView from './SavedMealsView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const content = screenFixture<SavedMealsShowOutput>('saved_meals_show');
const empty = screenFixture<SavedMealsShowOutput>('saved_meals_show.empty');

let wrapper: VueWrapper | undefined;

function show(props: { payload?: SavedMealsShowOutput; pending?: boolean } = {}): VueWrapper {
    wrapper = mount(SavedMealsView, { props, attachTo: document.body });
    return wrapper;
}

async function rowAction(view: VueWrapper, meal: string, title: string): Promise<void> {
    await view.get(`button[aria-label="${meal} menu"]`).trigger('click');
    await view.findAll('[role="menuitem"]').find((el) => el.text() === title)!.trigger('click');
    await flushPromises();
}

function renameField(): HTMLInputElement {
    return document.body.querySelector<HTMLInputElement>('[role="dialog"] input[name="saved-meal-name"]')!;
}

async function typeName(value: string): Promise<void> {
    renameField().value = value;
    renameField().dispatchEvent(new Event('input'));
    await flushPromises();
}

function save(): HTMLButtonElement {
    return document.body.querySelector<HTMLButtonElement>('[role="dialog"] [data-save]')!;
}

describe('SavedMealsView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({});
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('MEAL-071 lists the saved meals by name with the server\'s detail, without calling the server', () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Saved meals');
        const rows = view.findAllComponents(SavedMealRowView);
        expect(rows.map((row) => row.text())).toEqual([expect.stringContaining('Bulk dinner'), expect.stringContaining('Protein shake')]);
        expect(rows[0]!.text()).toContain('3 foods');
        expect(rows[1]!.text()).toContain('1 food');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MEAL-071 shows the server\'s empty line when nothing is saved', () => {
        const view = show({ payload: empty });

        expect(view.findAllComponents(SavedMealRowView)).toHaveLength(0);
        expect(view.getComponent(SavedMealsStatus).text()).toContain('No saved meals yet. Save one from a meal\'s menu.');
    });

    it('CAP-004 shows a skeleton while it reads saved_meals_show, then the list', async () => {
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('saved_meals_show', {}, expect.any(Function), expect.any(Function));
        expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(view.text()).toContain('Bulk dinner');
    });

    it('CAP-004 says why the list did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('saved_meals_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Your saved meals could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.text()).toContain('Protein shake');
    });

    it('OFF-008 marks each meal\'s food count as waiting to sync', () => {
        const view = show({ payload: content, pending: true });

        expect(view.findAllComponents(SavedMealRowView).every((row) => row.text().includes('Waiting to sync'))).toBe(true);
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        expect(show({ payload: content }).text()).not.toContain('Waiting to sync');
    });

    it('MEAL-071 each meal offers Rename, then Delete at the edge', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Bulk dinner menu"]').trigger('click');

        expect(view.findAll('[role="menuitem"]').map((item) => item.text())).toEqual(['Rename', 'Delete']);
    });

    it('MEAL-071 Rename opens with the meal\'s name and sends the trimmed new one', async () => {
        const view = show({ payload: content });

        await rowAction(view, 'Bulk dinner', 'Rename');
        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('Rename saved meal');
        expect(renameField().value).toBe('Bulk dinner');

        await typeName('  Lean dinner ');
        save().click();
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith(
            'saved_meal_rename',
            { saved_meal_id: content.meals[0]!.saved_meal_id, name: 'Lean dinner' },
            { idempotencyKey: expect.stringMatching(/^saved_meal_rename:/) },
        );
        expect(inputViolations('saved_meal_rename', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('saved_meals_show', {}, expect.any(Function), expect.any(Function));
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });

    it('MEAL-071 a blank name cannot be saved', async () => {
        const view = show({ payload: content });

        await rowAction(view, 'Bulk dinner', 'Rename');
        await typeName('   ');

        expect(save().disabled).toBe(true);
    });

    it('MEAL-071 the same name sends nothing and closes', async () => {
        const view = show({ payload: content });

        await rowAction(view, 'Bulk dinner', 'Rename');
        await typeName('Bulk dinner ');
        save().click();
        await flushPromises();

        expect(writeMock).not.toHaveBeenCalled();
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });

    it('CAP-012 a refused rename stays open with the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('saved_meal_rename', 422, { error: { message: 'A name can be up to 255 characters.' } }));
        const view = show({ payload: content });

        await rowAction(view, 'Bulk dinner', 'Rename');
        await typeName('x'.repeat(300));
        save().click();
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"] [role="alert"]')!.textContent).toContain('A name can be up to 255 characters.');

        document.body.querySelector<HTMLButtonElement>('[role="dialog"] [data-cancel]')!.click();
        await flushPromises();
        await rowAction(view, 'Protein shake', 'Rename');
        expect(document.body.querySelector('[role="dialog"] [role="alert"]')).toBeNull();
    });

    it('MEAL-071 Delete sends saved_meal_delete for that meal and reads the list again', async () => {
        const view = show({ payload: content });

        await rowAction(view, 'Protein shake', 'Delete');

        expect(writeMock).toHaveBeenCalledWith(
            'saved_meal_delete',
            { saved_meal_id: content.meals[1]!.saved_meal_id },
            { idempotencyKey: expect.stringMatching(/^saved_meal_delete:/) },
        );
        expect(inputViolations('saved_meal_delete', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('saved_meals_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-012 says why a delete was refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('saved_meal_delete', 404, { error: { message: 'That saved meal is gone.' } }));
        const view = show({ payload: content });

        await rowAction(view, 'Protein shake', 'Delete');

        expect(view.getComponent(SavedMealsStatus).get('[role="alert"]').text()).toContain('That saved meal is gone.');
    });
});

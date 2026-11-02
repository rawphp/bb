import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { SavedMealsShowOutput } from '@/contracts/saved_meals_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import SavedMealPickerSheet from './SavedMealPickerSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const content = screenFixture<SavedMealsShowOutput>('saved_meals_show');
const empty = screenFixture<SavedMealsShowOutput>('saved_meals_show.empty');
const MEAL_ID = '01JBX5M0000000000000000001';

let wrapper: VueWrapper | undefined;

type Reload = (options?: { fresh?: boolean }) => Promise<void>;

function show(props: { payload?: SavedMealsShowOutput; open?: boolean; reload?: Reload } = {}): VueWrapper {
    wrapper = mount(SavedMealPickerSheet, { props: { open: true, mealId: MEAL_ID, reload: vi.fn().mockResolvedValue(undefined), ...props }, attachTo: document.body });
    return wrapper;
}

function dialog(): HTMLElement {
    return document.body.querySelector<HTMLElement>('[role="dialog"]')!;
}

function pickButton(name: string): HTMLButtonElement {
    return [...dialog().querySelectorAll<HTMLButtonElement>('button[data-pick]')].find((b) => b.textContent!.includes(name))!;
}

describe('SavedMealPickerSheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({});
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('MEAL-070 lists the saved meals to load, under Load saved meal', () => {
        show({ payload: content });

        expect(dialog().querySelector('h2')!.textContent).toBe('Load saved meal');
        expect(dialog().textContent).toContain('Bulk dinner');
        expect(dialog().textContent).toContain('3 foods');
        expect(dialog().querySelector('[data-save]')).toBeNull();
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MEAL-070 a picked meal is loaded into this meal with meal_saved_meal_load, the meal is read again, then the sheet reports it', async () => {
        let reread: () => void = () => {};
        const reload = vi.fn(() => new Promise<void>((resolve) => (reread = resolve)));
        const view = show({ payload: content, reload });

        pickButton('Protein shake').click();
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith(
            'meal_saved_meal_load',
            { meal_id: MEAL_ID, saved_meal_id: content.meals[1]!.saved_meal_id },
            { idempotencyKey: expect.stringMatching(/^meal_saved_meal_load:/) },
        );
        expect(inputViolations('meal_saved_meal_load', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(view.emitted('loaded')).toBeUndefined();

        reread();
        await flushPromises();
        expect(view.emitted('loaded')).toHaveLength(1);
    });

    it('CAP-014 a load with no final answer has the meal read from the server, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_saved_meal_load', 504, null));
        const reload = vi.fn().mockResolvedValue(undefined);
        const view = show({ payload: content, reload });

        pickButton('Bulk dinner').click();
        await flushPromises();

        expect(reload).toHaveBeenCalledWith({ fresh: true });
        expect(view.emitted('loaded')).toBeUndefined();
    });

    it('MEAL-070 opening again reads the saved meals again and drops the last refusal', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_saved_meal_load', 422, { error: { message: 'That meal is locked.' } }));
        const view = show();
        await flushPromises();
        pickButton('Bulk dinner').click();
        await flushPromises();
        await view.setProps({ open: false });
        readMock.mockClear();

        await view.setProps({ open: true });
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('saved_meals_show', {}, expect.any(Function), expect.any(Function));
        expect(dialog().querySelector('[role="alert"]')).toBeNull();
    });

    it('CAP-012 a refused load stays open with the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_saved_meal_load', 422, { error: { message: 'That meal is locked.' } }));
        const view = show({ payload: content });

        pickButton('Bulk dinner').click();
        await flushPromises();

        expect(dialog().querySelector('[role="alert"]')!.textContent).toContain('That meal is locked.');
        expect(view.emitted('loaded')).toBeUndefined();
    });

    it('MEAL-070 shows the server\'s empty line when nothing is saved', () => {
        show({ payload: empty });

        expect(dialog().querySelectorAll('button[data-pick]')).toHaveLength(0);
        expect(dialog().textContent).toContain('No saved meals yet. Save one from a meal\'s menu.');
    });

    it('VIEW-004 a sheet shown from its fixture calls nothing when it opens again', async () => {
        const view = show({ payload: content });

        await view.setProps({ open: false });
        await view.setProps({ open: true });
        await flushPromises();

        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-004 reads saved_meals_show with a skeleton until it answers', async () => {
        show();

        expect(dialog().querySelector('[data-test="skeleton"]')).not.toBeNull();
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('saved_meals_show', {}, expect.any(Function), expect.any(Function));
        expect(dialog().textContent).toContain('Bulk dinner');
    });

    it('CAP-004 says why the list did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('saved_meals_show', 500, null)).mockResolvedValueOnce(content);
        show();
        await flushPromises();

        expect(dialog().querySelector('[role="alert"]')!.textContent).toContain('Your saved meals could not be loaded.');
        dialog().querySelector<HTMLButtonElement>('[data-test="retry"]')!.click();
        await flushPromises();
        expect(dialog().textContent).toContain('Bulk dinner');
    });

    it('UI-005 Cancel closes without loading anything', async () => {
        const view = show({ payload: content });

        dialog().querySelector<HTMLButtonElement>('[data-cancel]')!.click();
        await flushPromises();

        expect(view.emitted('cancel')).toHaveLength(1);
        expect(writeMock).not.toHaveBeenCalled();
    });
});

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import type { CustomFoodsShowOutput } from '@/contracts/custom_foods_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import { useRouter } from 'vue-router';
import CustomFoodsView from './CustomFoodsView.vue';

const back = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: vi.fn(() => ({ back })) }));
vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);
const writeMock = vi.mocked(write);
const content = screenFixture<CustomFoodsShowOutput>('custom_foods_show');
const empty = screenFixture<CustomFoodsShowOutput>('custom_foods_show.empty');

let wrapper: VueWrapper | undefined;

function show(props: { payload?: CustomFoodsShowOutput; pending?: boolean; create?: string } = {}): VueWrapper {
    wrapper = mount(CustomFoodsView, { props, attachTo: document.body });
    return wrapper;
}

function dialogTitle(): string | null {
    return document.body.querySelector('[role="dialog"] h2')?.textContent ?? null;
}

async function rowAction(view: VueWrapper, food: string, title: string): Promise<void> {
    await view.get(`button[aria-label="${food} menu"]`).trigger('click');
    const item = view.findAll('[role="menuitem"]').find((el) => el.text() === title)!;
    await item.trigger('click');
    await flushPromises();
}

describe('CustomFoodsView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        readFreshMock.mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({});
        back.mockReset();
        vi.mocked(useRouter).mockClear();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('FOOD-061 lists the custom foods with their brand and the server\'s serving and calories, without calling the server', () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Custom foods');
        const rows = view.findAll('li');
        expect(rows).toHaveLength(2);
        expect(rows[0]!.text()).toContain('Mum\'s granola');
        expect(rows[0]!.text()).toContain('Mum\'s granola1 cup · 420 kcal');
        expect(rows[1]!.text()).toContain('Protein pancakes');
        expect(rows[1]!.text()).toContain('Myprotein\n100 g · 210 kcal');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('FOOD-061 shows the server\'s empty line when there are no custom foods', () => {
        const view = show({ payload: empty });

        expect(view.findAll('li')).toHaveLength(0);
        expect(view.text()).toContain('No custom foods yet. Create one from the Pantry.');
    });

    it('CAP-004 shows a skeleton while it reads custom_foods_show, then the list', async () => {
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('custom_foods_show', {}, expect.any(Function), expect.any(Function));
        expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(view.text()).toContain('Mum\'s granola');
    });

    it('CAP-004 says why the list did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('custom_foods_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Your custom foods could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.text()).toContain('Protein pancakes');
    });

    it('OFF-008 marks each food\'s serving and calories as waiting to sync', () => {
        const view = show({ payload: content, pending: true });

        expect(view.findAll('li').every((row) => row.text().includes('Waiting to sync'))).toBe(true);
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        const view = show({ payload: content });

        expect(view.text()).not.toContain('Waiting to sync');
    });

    it('FOOD-060 + opens a blank new custom food', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="New custom food"]').trigger('click');

        expect(dialogTitle()).toBe('New custom food');
        expect(document.body.querySelector<HTMLInputElement>('[name="name"]')!.value).toBe('');
    });

    it('FOOD-061 a food\'s row opens it for editing', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Edit Protein pancakes"]').trigger('click');

        expect(dialogTitle()).toBe('Edit custom food');
        expect(document.body.querySelector<HTMLInputElement>('[name="name"]')!.value).toBe('Protein pancakes');
    });

    it('FOOD-061 Edit in a food\'s menu opens it for editing too', async () => {
        const view = show({ payload: content });

        await rowAction(view, 'Mum\'s granola', 'Edit');

        expect(dialogTitle()).toBe('Edit custom food');
        expect(document.body.querySelector<HTMLInputElement>('[name="name"]')!.value).toBe('Mum\'s granola');
    });

    it('FOOD-061 a saved food closes the sheet and reads the list again', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Edit Protein pancakes"]').trigger('click');
        document.body.querySelector<HTMLButtonElement>('[data-save]')!.click();
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith('custom_food_save', expect.objectContaining({ id: content.foods[1]!.food_id }), expect.anything());
        expect(readMock).toHaveBeenCalledWith('custom_foods_show', {}, expect.any(Function), expect.any(Function));
        expect(dialogTitle()).toBeNull();
    });

    it('FOOD-061 Delete sends custom_food_delete for that food and reads the list again', async () => {
        const view = show({ payload: content });

        await rowAction(view, 'Protein pancakes', 'Delete');

        expect(writeMock).toHaveBeenCalledWith('custom_food_delete', { food_id: content.foods[1]!.food_id }, { idempotencyKey: expect.stringMatching(/^custom_food_delete:/) });
        expect(inputViolations('custom_food_delete', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('custom_foods_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-014 a save that got no final answer reads the list from the server, so a food it made shows up', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('custom_food_save', 504, null));
        const view = show({ payload: content });

        await view.get('button[aria-label="Edit Protein pancakes"]').trigger('click');
        document.body.querySelector<HTMLButtonElement>('[data-save]')!.click();
        await flushPromises();

        expect(readFreshMock).toHaveBeenCalledWith('custom_foods_show', {});
        expect(document.body.querySelector('[role="dialog"] [role="alert"]')!.textContent).toContain('may have worked');
    });

    it('FOOD-062 opened to create a food, it starts the sheet with that name and goes back once it is saved', async () => {
        show({ payload: content, create: 'chicken' });
        await flushPromises();

        expect(dialogTitle()).toBe('New custom food');
        const name = document.body.querySelector<HTMLInputElement>('[name="name"]')!;
        expect(name.value).toBe('chicken');
        for (const [field, value] of [['serving_size', '100'], ['serving_grams', '100'], ['protein', '31'], ['fat', '3.6'], ['carbs', '0'], ['fiber', '0']]) {
            const input = document.body.querySelector<HTMLInputElement>(`[name="${field}"]`)!;
            input.value = value;
            input.dispatchEvent(new Event('input'));
        }
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-save]')!.click();
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith('custom_food_save', expect.objectContaining({ name: 'chicken' }), expect.anything());
        expect(inputViolations('custom_food_save', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(back).toHaveBeenCalledTimes(1);
    });

    it('FOOD-062 opened to create a food, Cancel goes back to where it came from', async () => {
        show({ payload: content, create: 'chicken' });
        await flushPromises();

        document.body.querySelector<HTMLButtonElement>('[data-cancel]')!.click();
        await flushPromises();

        expect(back).toHaveBeenCalledTimes(1);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('FOOD-061 opened from More, closing the sheet stays on the list', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="New custom food"]').trigger('click');
        document.body.querySelector<HTMLButtonElement>('[data-cancel]')!.click();
        await flushPromises();

        expect(back).not.toHaveBeenCalled();
        expect(dialogTitle()).toBeNull();
    });

    it('CAP-012 says why a delete was refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('custom_food_delete', 422, { error: { message: 'That food is in a saved meal.' } }));
        const view = show({ payload: content });

        await rowAction(view, 'Protein pancakes', 'Delete');

        expect(view.get('[role="alert"]').text()).toContain('That food is in a saved meal.');
    });
});

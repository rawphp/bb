import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, write } from '@/app/call';
import type { CustomFoodsShowOutput } from '@/contracts/custom_foods_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import CustomFoodSheet from './CustomFoodSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const writeMock = vi.mocked(write);
const foods = screenFixture<CustomFoodsShowOutput>('custom_foods_show').foods;
const granola = foods[0]!;
const pancakes = foods[1]!;

type Props = { open?: boolean; reload?: (options?: { fresh?: boolean }) => Promise<void>; food?: CustomFoodsShowOutput['foods'][number] | null; name?: string; barcode?: string };

function show(props: Props = {}) {
    return mount(CustomFoodSheet, { props: { open: true, reload: vi.fn().mockResolvedValue(undefined), ...props }, attachTo: document.body });
}

function field(name: string): HTMLInputElement | HTMLSelectElement {
    return document.body.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`)!;
}

async function type(name: string, value: string): Promise<void> {
    const el = field(name);
    el.value = value;
    el.dispatchEvent(new Event(el instanceof HTMLSelectElement ? 'change' : 'input'));
    await flushPromises();
}

function button(selector: string): HTMLButtonElement {
    return document.body.querySelector<HTMLButtonElement>(selector)!;
}

async function fillRequired(): Promise<void> {
    await type('name', '  Chia seeds  ');
    await type('serving_size', '1');
    await type('serving_unit', 'tsp');
    await type('serving_grams', '5');
    await type('protein', '1');
    await type('fat', '2');
    await type('carbs', '2');
    await type('fiber', '2');
}

describe('CustomFoodSheet', () => {
    beforeEach(() => writeMock.mockReset().mockResolvedValue({}));
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('FOOD-060 a new food asks for name, brand, barcode, serving size and unit, weight per serving and the four macros', () => {
        const wrapper = show();

        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('New custom food');
        for (const name of ['name', 'brand', 'barcode', 'serving_size', 'serving_unit', 'serving_grams', 'protein', 'fat', 'carbs', 'fiber']) {
            expect(field(name), name).not.toBeNull();
        }
        expect([...(field('serving_unit') as HTMLSelectElement).options].map((o) => [o.value, o.text])).toEqual([
            ['g', 'Grams'],
            ['cup', 'Cups'],
            ['tbsp', 'Tablespoons'],
            ['tsp', 'Teaspoons'],
            ['piece', 'Pieces'],
        ]);
        expect(document.body.textContent).toContain('Nutrition per serving');
        wrapper.unmount();
    });

    it('FOOD-060 Save stays off until the name and every number are filled in', async () => {
        const wrapper = show();

        expect(button('[data-save]').disabled).toBe(true);
        await fillRequired();
        expect(button('[data-save]').disabled).toBe(false);
        await type('fiber', 'lots');
        expect(button('[data-save]').disabled).toBe(true);
        await type('fiber', '2');
        await type('name', '   ');
        expect(button('[data-save]').disabled).toBe(true);
        wrapper.unmount();
    });

    it('FOOD-060 saves a new food with a fresh ULID, what was typed as numbers, and no brand or barcode when they are blank', async () => {
        const wrapper = show();

        await fillRequired();
        await type('carbs', '2,5');
        button('[data-save]').click();
        await flushPromises();

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('custom_food_save');
        expect(input).toEqual({
            id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/),
            name: 'Chia seeds',
            brand: null,
            barcode: null,
            serving_size: 1,
            serving_unit: 'tsp',
            serving_grams: 5,
            protein: 1,
            fat: 2,
            carbs: 2.5,
            fiber: 2,
        });
        expect(inputViolations('custom_food_save', input)).toEqual([]);
        expect(wrapper.emitted('saved')).toEqual([[(input as { id: string }).id]]);
        wrapper.unmount();
    });

    it('FOOD-061 editing opens with every value of the food filled in and saves it under its own id', async () => {
        const wrapper = show({ food: granola });

        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('Edit custom food');
        await type('protein', '11');
        button('[data-save]').click();
        await flushPromises();

        const input = writeMock.mock.calls[0]![1];
        expect(input).toEqual({
            id: granola.food_id,
            name: 'Mum\'s granola',
            brand: null,
            barcode: null,
            serving_size: 1,
            serving_unit: 'cup',
            serving_grams: 95,
            protein: 11,
            fat: 16,
            carbs: 58,
            fiber: 6,
        });
        expect(inputViolations('custom_food_save', input)).toEqual([]);
        wrapper.unmount();
    });

    it('FOOD-061 editing a branded food with a barcode keeps both', async () => {
        const wrapper = show({ food: pancakes });

        expect(field('brand').value).toBe('Myprotein');
        expect(field('barcode').value).toBe('5055534301234');
        button('[data-save]').click();
        await flushPromises();

        expect(writeMock.mock.calls[0]![1]).toMatchObject({ id: pancakes.food_id, brand: 'Myprotein', barcode: '5055534301234' });
        wrapper.unmount();
    });

    it('CAP-010 a saved food has the screen underneath read again before the sheet reports it', async () => {
        let reread: () => void = () => {};
        const reload = vi.fn(() => new Promise<void>((resolve) => (reread = resolve)));
        const wrapper = show({ food: pancakes, reload });

        button('[data-save]').click();
        await flushPromises();
        expect(reload).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('saved')).toBeUndefined();

        reread();
        await flushPromises();
        expect(wrapper.emitted('saved')).toHaveLength(1);
        wrapper.unmount();
    });

    it('CAP-014 a save with no final answer has the screen underneath read the server\'s copy, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('custom_food_save', 504, null));
        const reload = vi.fn().mockResolvedValue(undefined);
        const wrapper = show({ food: pancakes, reload });

        button('[data-save]').click();
        await flushPromises();

        expect(reload).toHaveBeenCalledWith({ fresh: true });
        expect(wrapper.emitted('saved')).toBeUndefined();
        wrapper.unmount();
    });

    it('FOOD-062 a new food can start from the search text as its name, and a barcode that found nothing', () => {
        const wrapper = show({ name: 'Grandma oats', barcode: '0123' });

        expect(field('name').value).toBe('Grandma oats');
        expect(field('barcode').value).toBe('0123');
        wrapper.unmount();
    });

    it('CAP-012 shows the server\'s words when it refuses the food, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('custom_food_save', 422, { error: { message: 'Protein can\'t weigh more than the serving.' } }));
        const wrapper = show();

        await fillRequired();
        button('[data-save]').click();
        await flushPromises();

        expect(document.body.querySelector('[role="alert"]')!.textContent).toContain('Protein can\'t weigh more than the serving.');
        expect(wrapper.emitted('saved')).toBeUndefined();

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        expect(document.body.querySelector('[role="dialog"] [role="alert"]')).toBeNull();
        wrapper.unmount();
    });

    it('UI-005 Cancel closes without saving', async () => {
        const wrapper = show();

        button('[data-cancel]').click();
        await flushPromises();

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(writeMock).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('FOOD-060 opening again for a new food starts blank with a new id', async () => {
        const wrapper = show();

        await fillRequired();
        button('[data-save]').click();
        await flushPromises();
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(field('name').value).toBe('');
        await fillRequired();
        button('[data-save]').click();
        await flushPromises();

        const first = (writeMock.mock.calls[0]![1] as { id: string }).id;
        const second = (writeMock.mock.calls[1]![1] as { id: string }).id;
        expect(second).not.toBe(first);
        wrapper.unmount();
    });
});

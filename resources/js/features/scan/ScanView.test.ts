import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';
import { CapabilityError, readFresh, write } from '@/app/call';
import type { FoodBarcodeLookupOutput } from '@/contracts/food_barcode_lookup';
import type { ScanLabelOutput } from '@/contracts/scan_label';
import type { ScanPhotoOutput } from '@/contracts/scan_photo';
import { inputViolations, screenFixture } from '@/testing/schemas';
import ScanView from './ScanView.vue';
import { prepareImage, readBarcode, ReaderUnavailable } from './useCamera';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const camera = vi.hoisted(() => ({ access: { value: 'ready' as string }, live: { value: false }, start: vi.fn(), stop: vi.fn() }));
vi.mock('./useCamera', async () => {
    const { ref } = await import('vue');
    const access = ref('ready');
    const live = ref(false);
    camera.access = access;
    camera.live = live;

    class ReaderUnavailable extends Error {}

    return { useCamera: () => ({ access, live, start: camera.start, stop: camera.stop }), readBarcode: vi.fn(), prepareImage: vi.fn(), ReaderUnavailable };
});

const readMock = vi.mocked(readFresh);
const writeMock = vi.mocked(write);
const MEAL = '01JBX5M0000000000000000001';
const found = () => screenFixture<FoodBarcodeLookupOutput>('food_barcode_lookup');
const notFound = () => screenFixture<FoodBarcodeLookupOutput>('food_barcode_lookup.not-found');
const draft = (state?: string) => screenFixture<ScanPhotoOutput>(state ? `scan_photo.${state}` : 'scan_photo');
const label = () => screenFixture<ScanLabelOutput>('scan_label');

function scan(props: Record<string, unknown> = {}, slots: Record<string, (scope: never) => unknown> = {}): VueWrapper {
    return mount(ScanView, { props: { mealId: MEAL, ...props }, slots, attachTo: document.body, global: { stubs: { teleport: true } } });
}

/** Each call sent, as [name, input], checked against its input schema snapshot. */
function calls(mock: { mock: { calls: unknown[][] } }): [string, Record<string, unknown>][] {
    return mock.mock.calls.map(([name, input]) => {
        expect(inputViolations(name as string, input), name as string).toEqual([]);
        return [name as string, input as Record<string, unknown>];
    });
}

async function pickMode(wrapper: VueWrapper, title: string): Promise<void> {
    await wrapper.findAll('[role="radio"]').find((radio) => radio.text() === title)!.trigger('click');
}

async function choose(wrapper: VueWrapper, selector: string, file: File): Promise<void> {
    const input = wrapper.find<HTMLInputElement>(selector);
    Object.defineProperty(input.element, 'files', { configurable: true, value: [file] });
    await input.trigger('change');
    await flushPromises();
}

const photo = new File(['jpeg'], 'plate.jpg', { type: 'image/jpeg' });

beforeEach(() => {
    readMock.mockReset();
    writeMock.mockReset().mockResolvedValue({});
    camera.start.mockReset().mockResolvedValue(undefined);
    camera.stop.mockReset();
    camera.access.value = 'ready';
    camera.live.value = false;
    vi.mocked(readBarcode).mockReset();
    vi.mocked(prepareImage).mockReset().mockResolvedValue('data:image/jpeg;base64,AAAA');
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
});

describe('ScanView', () => {
    it('FOOD-040 offers Barcode, Nutrition label and Photo in one scanner, starting on Barcode', async () => {
        const wrapper = scan();

        expect(wrapper.text()).toContain('Scan a food');
        expect(wrapper.findAll('[role="radio"]').map((radio) => radio.text())).toEqual(['Barcode', 'Nutrition label', 'Photo']);
        expect(wrapper.find('[role="radio"][aria-checked="true"]').text()).toBe('Barcode');

        await wrapper.find('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[false]]);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('FOOD-041 looks up a typed barcode and adds the food it finds to the meal', async () => {
        readMock.mockResolvedValue(found());
        const wrapper = scan();

        await wrapper.find('[data-test="typed-code"]').setValue(' 5000159484695 ');
        await wrapper.find('[data-test="look-up"]').trigger('click');
        await flushPromises();

        expect(calls(readMock)).toEqual([['food_barcode_lookup', { barcode: '5000159484695' }]]);
        const card = wrapper.find('[data-test="found"]');
        expect(card.text()).toContain('Protein bar, cookie dough');
        expect(card.text()).toContain('Grenade');
        expect(card.text()).toContain('380 kcal · P 32 · F 14 · C 30 per 100 g');

        await card.find('[data-test="add-found"]').trigger('click');
        await flushPromises();

        const [name, input] = calls(writeMock)[0]!;
        expect(name).toBe('meal_food_add');
        expect(input).toEqual({ meal_id: MEAL, entries: [{ id: expect.any(String), food_id: '01JBX5F0000000000000000009', weighing: 'raw' }] });
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('FOOD-041 offers the label scan or a custom food, with the barcode, for a code it does not know', async () => {
        const wrapper = scan({ payload: notFound() }, { 'custom-food': ({ barcode, draft }: { barcode: string | null; draft: unknown }) => h('p', { 'data-test': 'custom-food' }, `${barcode} ${draft}`) });

        expect(wrapper.find('[data-test="not-found"]').text()).toContain("We couldn't find 0123456789012.");

        await wrapper.find('[data-test="create-food"]').trigger('click');
        expect(wrapper.find('[data-test="custom-food"]').text()).toBe('0123456789012 null');

        await wrapper.find('[data-test="scan-label"]').trigger('click');
        expect(wrapper.find('[role="radio"][aria-checked="true"]').text()).toBe('Nutrition label');
        expect(wrapper.text()).toContain('Barcode 0123456789012 is saved with the food.');
    });

    it('FOOD-041 adds the custom food the form saves to the meal and closes', async () => {
        const wrapper = scan({ payload: notFound() }, {
            'custom-food': ({ saved }: { saved: (id: string) => void }) => h('button', { 'data-test': 'form-saved', onClick: () => saved('01JBX5F00000000000000000CF') }),
        });

        await wrapper.find('[data-test="create-food"]').trigger('click');
        await wrapper.find('[data-test="form-saved"]').trigger('click');
        await flushPromises();

        expect(calls(writeMock)).toEqual([['meal_food_add', { meal_id: MEAL, entries: [{ id: expect.any(String), food_id: '01JBX5F00000000000000000CF', weighing: 'raw' }] }]]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('PWA-031 reads a barcode from the live camera and looks it up', async () => {
        readMock.mockResolvedValue(found());
        const wrapper = scan();

        await wrapper.find('[data-test="scan-live"]').trigger('click');
        const [video, onCode] = camera.start.mock.calls[0]!;
        expect(video).toBeInstanceOf(HTMLVideoElement);

        (onCode as (code: string) => void)('5000159484695');
        await flushPromises();

        expect(calls(readMock)).toEqual([['food_barcode_lookup', { barcode: '5000159484695' }]]);
        expect(wrapper.find('[data-test="found"]').exists()).toBe(true);
    });

    it('PWA-031 reads the barcode in a chosen photo, and says when there is none', async () => {
        readMock.mockResolvedValue(found());
        vi.mocked(readBarcode).mockResolvedValueOnce('5000159484695').mockResolvedValueOnce(null);
        const wrapper = scan();

        await choose(wrapper, '[data-test="choose-photo"]', photo);
        expect(readBarcode).toHaveBeenCalledWith(photo);
        expect(calls(readMock)).toEqual([['food_barcode_lookup', { barcode: '5000159484695' }]]);

        await choose(wrapper, '[data-test="choose-photo"]', photo);
        expect(wrapper.find('[data-test="error"]').text()).toBe('No barcode found in that photo. Try again or type the code.');
        expect(wrapper.find('[data-test="found"]').exists()).toBe(false);
    });

    it('FOOD-040 says how to turn the camera on when it is off, and still offers a photo and typing', () => {
        camera.access.value = 'denied';
        const wrapper = scan();

        expect(wrapper.find('[data-test="scan-live"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Camera access is off.');
        expect(wrapper.find('[data-test="choose-photo"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="typed-code"]').exists()).toBe(true);
    });

    it('PLT-003 offers a photo upload on a device with no camera', async () => {
        camera.access.value = 'none';
        const wrapper = scan();

        expect(wrapper.find('[data-test="scan-live"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="choose-photo"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Camera access is off.');

        await pickMode(wrapper, 'Photo');
        expect(wrapper.find('[data-test="take-photo"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="choose-photo"]').exists()).toBe(true);
    });

    it('PWA-032 opens the camera directly for a label or plate photo, and offers the library', async () => {
        const wrapper = scan();

        await pickMode(wrapper, 'Nutrition label');

        expect(wrapper.find('[data-test="take-photo"]').attributes()).toMatchObject({ type: 'file', accept: 'image/*', capture: 'environment' });
        expect(wrapper.find('[data-test="choose-photo"]').attributes()).toMatchObject({ type: 'file', accept: 'image/*' });
        expect(wrapper.find('[data-test="choose-photo"]').attributes('capture')).toBeUndefined();
    });

    it('FOOD-042 reads a label photo into the custom food form, carrying the barcode that found nothing', async () => {
        readMock.mockResolvedValue(label());
        const wrapper = scan({ payload: notFound() }, { 'custom-food': ({ draft }: { draft: ScanLabelOutput | null }) => h('p', { 'data-test': 'custom-food' }, draft?.name ?? '') });

        await wrapper.find('[data-test="scan-label"]').trigger('click');
        await choose(wrapper, '[data-test="take-photo"]', photo);

        expect(prepareImage).toHaveBeenCalledWith(photo);
        expect(calls(readMock)).toEqual([['scan_label', { image: 'data:image/jpeg;base64,AAAA', barcode: '0123456789012' }]]);
        expect(wrapper.find('[data-test="custom-food"]').text()).toBe('Crunchy oat bar');
    });

    it('FOOD-043 lists each food of the plate with its match, grams and calories, and the totals', () => {
        const wrapper = scan({ payload: draft() });
        const rows = wrapper.findAll('[data-test="row"]');

        expect(wrapper.find('[role="radio"][aria-checked="true"]').text()).toBe('Photo');
        expect(rows).toHaveLength(4);
        expect(rows[0]!.text()).toContain('Burrito bowl · Chipotle');
        expect(rows[0]!.text()).toContain('225 kcal');
        expect(rows[0]!.find<HTMLInputElement>('[data-test="grams"]').element.value).toBe('150');
        expect(rows[0]!.find('[data-test="change-match"]').text()).toBe('Change');
        expect(rows[1]!.text()).toContain('No match');
        expect(rows[1]!.find('[data-test="change-match"]').text()).toBe('Find a match');
        expect(rows[1]!.find<HTMLInputElement>('[data-test="grams"]').element.value).toBe('37.5');
        expect(wrapper.text()).toContain("1 food has no match and won't be added or saved.");
        expect(wrapper.find('[data-test="totals"]').text()).toContain('743 kcal · P 63 · F 12 · C 89');
        expect(wrapper.find('[data-test="portion"]').text()).toBe('1.25×');
    });

    it('FOOD-043 recalculates the list on the server when an amount is typed', async () => {
        readMock.mockResolvedValue(draft('empty'));
        const wrapper = scan({ payload: draft() });

        await wrapper.findAll('[data-test="grams"]')[1]!.setValue('50');
        await flushPromises();

        expect(calls(readMock)).toEqual([
            ['scan_adjust', {
                rows: [
                    { name: 'Burrito bowl', grams: 150, base_grams: 120, food_id: '01JBX5F0000000000000000077' },
                    { name: 'House sauce', grams: 50, food_id: null },
                    { name: 'Grilled chicken', grams: 187.5, base_grams: 150, food_id: '01JBX5F0000000000000000042' },
                    { name: 'Rice', grams: 225, base_grams: 180, food_id: '01JBX5F0000000000000000041' },
                ],
                from_portion: 1.25,
                portion: 1.25,
                saved_meal_name: 'Scanned meal',
            }],
        ]);
        expect(wrapper.findAll('[data-test="row"]')).toHaveLength(0);
    });

    it('FOOD-043 ignores an amount that is not a number above zero', async () => {
        const wrapper = scan({ payload: draft() });
        const grams = wrapper.findAll('[data-test="grams"]')[0]!;

        await grams.setValue('0');
        await grams.setValue('abc');
        await grams.trigger('blur');

        expect(readMock).not.toHaveBeenCalled();
        expect((grams.element as HTMLInputElement).value).toBe('150');
    });

    it('FOOD-043 removes a row and matches a row to the food picked in the Pantry', async () => {
        readMock.mockResolvedValue(draft());
        const wrapper = scan({ payload: draft() }, {
            match: ({ search, picked }: { search: string; picked: (id: string) => void }) => h('button', { 'data-test': 'pantry', onClick: () => picked('01JBX5F00000000000000000SC') }, search),
        });

        await wrapper.findAll('[data-test="remove"]')[3]!.trigger('click');
        await flushPromises();
        expect((calls(readMock)[0]![1].rows as { name: string }[]).map((row) => row.name)).toEqual(['Burrito bowl', 'House sauce', 'Grilled chicken']);

        await wrapper.findAll('[data-test="change-match"]')[1]!.trigger('click');
        expect(wrapper.find('[data-test="pantry"]').text()).toBe('House sauce');
        await wrapper.find('[data-test="pantry"]').trigger('click');
        await flushPromises();

        const rows = calls(readMock)[1]![1].rows as { name: string; food_id: string | null; base_grams?: number }[];
        expect(rows[1]).toEqual({ name: 'House sauce', grams: 37.5, base_grams: 30, food_id: '01JBX5F00000000000000000SC' });
        expect(wrapper.find('[data-test="pantry"]').exists()).toBe(false);
    });

    it('FOOD-044 estimates the same list from a typed description', async () => {
        readMock.mockResolvedValue(draft());
        const wrapper = scan();

        await pickMode(wrapper, 'Photo');
        expect(wrapper.find('[data-test="estimate"]').attributes('disabled')).toBeDefined();
        await wrapper.find('[data-test="describe"]').setValue('chicken burrito bowl with rice');
        await wrapper.find('[data-test="estimate"]').trigger('click');
        await flushPromises();

        expect(calls(readMock)).toEqual([['scan_describe', { description: 'chicken burrito bowl with rice' }]]);
        expect(wrapper.findAll('[data-test="row"]')).toHaveLength(4);
    });

    it('FOOD-043 estimates the foods of a plate photo', async () => {
        readMock.mockResolvedValue(draft());
        const wrapper = scan();

        await pickMode(wrapper, 'Photo');
        await choose(wrapper, '[data-test="choose-photo"]', photo);

        expect(calls(readMock)).toEqual([['scan_photo', { image: 'data:image/jpeg;base64,AAAA' }]]);
        expect(wrapper.findAll('[data-test="row"]')).toHaveLength(4);
    });

    it('FOOD-045 scales the whole meal with the portion control, within the server\'s steps', async () => {
        readMock.mockResolvedValue({ ...draft(), portion: 1.5, portion_text: '1.5×', portion_up: null });
        const wrapper = scan({ payload: draft() });

        await wrapper.find('[data-test="portion-up"]').trigger('click');
        await flushPromises();

        expect(calls(readMock)[0]![1]).toMatchObject({ from_portion: 1.25, portion: 1.5 });
        expect(wrapper.find('[data-test="portion"]').text()).toBe('1.5×');
        expect(wrapper.find('[data-test="portion-up"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="portion-down"]').attributes('disabled')).toBeUndefined();
    });

    it('FOOD-043 adds the matched foods at their grams to the meal and closes', async () => {
        const wrapper = scan({ payload: draft() });

        await wrapper.find('[data-test="add-scanned"]').trigger('click');
        await flushPromises();

        const [name, input] = calls(writeMock)[0]!;
        expect(name).toBe('meal_food_add');
        expect(input).toEqual({
            meal_id: MEAL,
            entries: [
                { id: expect.any(String), food_id: '01JBX5F0000000000000000077', weighing: 'raw', grams: 150 },
                { id: expect.any(String), food_id: '01JBX5F0000000000000000042', weighing: 'raw', grams: 187.5 },
                { id: expect.any(String), food_id: '01JBX5F0000000000000000041', weighing: 'raw', grams: 225 },
            ],
        });
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('FOOD-046 saves the scanned meal under a name as a saved meal, once', async () => {
        const wrapper = scan({ payload: draft() });

        await wrapper.find('[data-test="save-meal"]').trigger('click');
        const name = wrapper.find<HTMLInputElement>('[data-test="saved-meal-name"]');
        expect(name.element.value).toBe('Scanned meal');
        await name.setValue('  Burrito night ');
        await wrapper.findAll('[data-save]').at(-1)!.trigger('click');
        await flushPromises();

        expect(calls(writeMock)).toEqual([
            ['saved_meal_save', { id: expect.any(String), name: 'Burrito night', foods: draft().matched }],
        ]);
        expect(wrapper.text()).toContain('Saved “Burrito night” to your saved meals.');
        expect(wrapper.find('[data-test="save-meal"]').attributes('disabled')).toBeDefined();
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('FOOD-043 shows the server\'s words when nothing on the plate was found (scan_photo.empty)', () => {
        const wrapper = scan({ payload: draft('empty') });

        expect(wrapper.text()).toContain('No foods found. Try another photo or describe the meal.');
        expect(wrapper.find('[data-test="add-scanned"]').exists()).toBe(false);
    });

    it('FOOD-042 opens the custom food form on a read label (scan_label)', () => {
        const wrapper = scan({ payload: label() }, { 'custom-food': ({ draft }: { draft: ScanLabelOutput | null }) => h('p', { 'data-test': 'custom-food' }, draft?.barcode ?? '') });

        expect(wrapper.find('[role="radio"][aria-checked="true"]').text()).toBe('Nutrition label');
        expect(wrapper.find('[data-test="custom-food"]').text()).toBe('5012345678900');
    });

    it('FOOD-043 says what it is doing while a call is out, and takes no other', async () => {
        let answer!: (value: unknown) => void;
        readMock.mockReturnValue(new Promise((resolve) => (answer = resolve)));
        const wrapper = scan();

        await pickMode(wrapper, 'Photo');
        await wrapper.find('[data-test="describe"]').setValue('oats');
        await wrapper.find('[data-test="estimate"]').trigger('click');

        expect(wrapper.find('[data-test="busy"]').text()).toBe('Estimating the foods');
        expect(wrapper.find('[data-test="estimate"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="choose-photo"]').attributes('disabled')).toBeDefined();
        expect(wrapper.findAll('[role="radio"]').every((radio) => radio.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.find('[data-cancel]').attributes('disabled')).toBeDefined();

        answer(draft());
        await flushPromises();
        expect(wrapper.find('[data-test="busy"]').exists()).toBe(false);
    });

    it('FOOD-044 shows the server\'s words when a scan is refused', async () => {
        readMock.mockRejectedValue(new CapabilityError('scan_describe', 503, { error: { code: 'ai_unavailable', message: 'Food scanning is not set up on this server.' } }));
        const wrapper = scan();

        await pickMode(wrapper, 'Photo');
        await wrapper.find('[data-test="describe"]').setValue('oats');
        await wrapper.find('[data-test="estimate"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="error"]').text()).toBe('Food scanning is not set up on this server.');

        await pickMode(wrapper, 'Barcode');
        expect(wrapper.find('[data-test="error"]').exists()).toBe(false);
    });

    it('FOOD-041 keeps the scanner open with the server\'s words when adding fails', async () => {
        writeMock.mockRejectedValue(new CapabilityError('meal_food_add', 422, { error: { code: 'validation_failed', message: 'This meal is checked in.' } }));
        const wrapper = scan({ payload: found() });

        await wrapper.find('[data-test="add-found"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="error"]').text()).toBe('This meal is checked in.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });
    it('PWA-031 turns the camera off when the user stops it, leaves barcode mode or picks a photo', async () => {
        vi.mocked(readBarcode).mockResolvedValue(null);
        const wrapper = scan();
        camera.live.value = true;
        await flushPromises();

        await wrapper.findAll('button').find((button) => button.text() === 'Stop camera')!.trigger('click');
        expect(camera.stop).toHaveBeenCalledTimes(1);

        await choose(wrapper, '[data-test="choose-photo"]', photo);
        expect(camera.stop).toHaveBeenCalledTimes(2);

        await pickMode(wrapper, 'Nutrition label');
        expect(camera.stop).toHaveBeenCalledTimes(3);
    });

    it('PWA-031 says when the barcode reader or the camera cannot start', async () => {
        vi.mocked(readBarcode).mockRejectedValue(new ReaderUnavailable());
        const wrapper = scan();

        await choose(wrapper, '[data-test="choose-photo"]', photo);
        expect(wrapper.find('[data-test="error"]').text()).toBe("The barcode reader couldn't load. Type the code instead.");

        camera.start.mockRejectedValueOnce(new ReaderUnavailable());
        await wrapper.find('[data-test="scan-live"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="error"]').text()).toBe("The barcode reader couldn't load. Type the code instead.");

        camera.start.mockRejectedValueOnce(new Error('busy'));
        await wrapper.find('[data-test="scan-live"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="error"]').text()).toBe("The camera couldn't start. Choose a photo or type the code.");
    });

    it('PWA-032 says when a photo cannot be read', async () => {
        vi.mocked(prepareImage).mockResolvedValue(null);
        const wrapper = scan();

        await pickMode(wrapper, 'Photo');
        await choose(wrapper, '[data-test="choose-photo"]', photo);

        expect(wrapper.find('[data-test="error"]').text()).toBe("Couldn't read that photo. Try another.");
        expect(readMock).not.toHaveBeenCalled();
    });

    it('FOOD-043 puts the list\'s grams back in the field when the server refuses a typed amount', async () => {
        readMock.mockRejectedValue(new CapabilityError('scan_adjust', 422, { error: { code: 'validation_failed', message: 'An amount is at least 0.1 g.' } }));
        // A real Teleport: the stub re-creates the sheet's content on every render, which would hide a field that keeps its text.
        const wrapper = mount(ScanView, { props: { mealId: MEAL, payload: draft() }, attachTo: document.body });
        // This sheet teleported last into the body that earlier tests left their sheets in.
        const sheet = () => [...document.querySelectorAll('[aria-modal="true"]')].at(-1)!;
        const field = () => sheet().querySelectorAll<HTMLInputElement>('[data-test="grams"]')[1]!;
        const typed = field();

        typed.value = '0.05';
        typed.dispatchEvent(new Event('input'));
        typed.dispatchEvent(new Event('change'));
        await flushPromises();

        expect(sheet().querySelector('[data-test="error"]')?.textContent).toContain('An amount is at least 0.1 g.');
        expect(field().value).toBe('37.5');
        wrapper.unmount();
    });

    it('FOOD-045 steps the portion down too', async () => {
        readMock.mockResolvedValue(draft());
        const wrapper = scan({ payload: draft() });

        await wrapper.find('[data-test="portion-down"]').trigger('click');
        await flushPromises();

        expect(calls(readMock)[0]![1]).toMatchObject({ from_portion: 1.25, portion: 1 });
    });

    it('FOOD-041 retries an add that got no answer with the same new rows, and a new food with new ones (CAP-019)', async () => {
        const timeout = () => new CapabilityError('meal_food_add', 0, { reason: 'timeout' });
        writeMock.mockRejectedValueOnce(timeout()).mockRejectedValueOnce(timeout());
        readMock.mockResolvedValue({ ...found(), food: { ...found().food!, food_id: '01JBX5F000000000000000000B', name: 'Oat bar' } });
        const wrapper = scan({ payload: found() });

        await wrapper.find('[data-test="add-found"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="error"]').text()).toContain('may have worked');
        await wrapper.find('[data-test="add-found"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-test="typed-code"]').setValue('5012345678900');
        await wrapper.find('[data-test="look-up"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-test="add-found"]').trigger('click');
        await flushPromises();

        const [first, retry, other] = calls(writeMock).map(([, input]) => (input.entries as { id: string; food_id: string }[])[0]!);
        expect(retry!.id).toBe(first!.id);
        expect(other!.food_id).toBe('01JBX5F000000000000000000B');
        expect(other!.id).not.toBe(first!.id);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('FOOD-041 tells the host the meal may have changed when it closes after an add that got no answer', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_food_add', 0, { reason: 'timeout' }));
        const wrapper = scan({ payload: found() });

        await wrapper.find('[data-test="add-found"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('FOOD-043 takes an amount typed with a decimal comma', async () => {
        readMock.mockResolvedValue(draft());
        const wrapper = scan({ payload: draft() });

        await wrapper.findAll('[data-test="grams"]')[1]!.setValue('50,5');
        await flushPromises();

        expect((calls(readMock)[0]![1].rows as { grams: number }[])[1]!.grams).toBe(50.5);
    });

    it('FOOD-046 starts the name from the list\'s suggestion for a new list, and after a cancel', async () => {
        readMock.mockResolvedValue({ ...draft(), saved_meal_name: 'Chicken burrito bowl' });
        const wrapper = scan({ payload: draft() });
        const name = () => wrapper.find<HTMLInputElement>('[data-test="saved-meal-name"]');

        await wrapper.find('[data-test="save-meal"]').trigger('click');
        await name().setValue('Taco');
        await wrapper.findAll('[data-cancel]').at(-1)!.trigger('click');
        await wrapper.find('[data-test="save-meal"]').trigger('click');
        expect(name().element.value).toBe('Scanned meal');

        await name().setValue('Taco');
        await wrapper.findAll('[data-cancel]').at(-1)!.trigger('click');
        await wrapper.find('[data-test="portion-up"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-test="save-meal"]').trigger('click');
        expect(name().element.value).toBe('Chicken burrito bowl');
    });

    it('FOOD-046 retries a save that got no answer with the same id and the name typed, and a new name with a new id', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('saved_meal_save', 0, { reason: 'timeout' })).mockRejectedValueOnce(new CapabilityError('saved_meal_save', 0, { reason: 'timeout' }));
        const wrapper = scan({ payload: draft() });
        const save = async (name?: string) => {
            await wrapper.find('[data-test="save-meal"]').trigger('click');
            if (name !== undefined) await wrapper.find('[data-test="saved-meal-name"]').setValue(name);
            await wrapper.findAll('[data-save]').at(-1)!.trigger('click');
            await flushPromises();
        };

        await save('Burrito night');
        await save();
        await save('Taco night');

        const sentNames = calls(writeMock).map(([, input]) => [input.id, input.name]);
        expect(sentNames[1]).toEqual(sentNames[0]);
        expect(sentNames[0]![1]).toBe('Burrito night');
        expect(sentNames[2]![1]).toBe('Taco night');
        expect(sentNames[2]![0]).not.toBe(sentNames[0]![0]);
    });

    it('FOOD-041 closes with nothing added after an add the server refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_food_add', 422, { error: { code: 'validation_failed', message: 'This meal is checked in.' } }));
        const wrapper = scan({ payload: found() });

        await wrapper.find('[data-test="add-found"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[false]]);
    });

    it('FOOD-046 starts the name from the new list\'s suggestion after a save', async () => {
        readMock.mockResolvedValue({ ...draft(), saved_meal_name: 'Chicken burrito bowl' });
        const wrapper = scan({ payload: draft() });

        await wrapper.find('[data-test="save-meal"]').trigger('click');
        await wrapper.find('[data-test="saved-meal-name"]').setValue('Taco');
        await wrapper.findAll('[data-save]').at(-1)!.trigger('click');
        await flushPromises();
        await wrapper.find('[data-test="portion-up"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-test="save-meal"]').trigger('click');

        expect(wrapper.find<HTMLInputElement>('[data-test="saved-meal-name"]').element.value).toBe('Chicken burrito bowl');
    });

    it('FOOD-041 shows one message at a time: a new scan clears a refused add', async () => {
        writeMock.mockRejectedValue(new CapabilityError('meal_food_add', 422, { error: { code: 'validation_failed', message: 'This meal is checked in.' } }));
        const wrapper = scan({ payload: found() });

        await wrapper.find('[data-test="add-found"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="error"]').exists()).toBe(true);

        await wrapper.find('[data-test="scan-live"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="error"]').exists()).toBe(false);
    });
});

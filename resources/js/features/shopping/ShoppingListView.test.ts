import 'fake-indexeddb/auto';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write, writeNow } from '@/app/call';
import type { OutboxEntry } from '@/app/outbox';
import type { ShoppingItemCheckInput } from '@/contracts/shopping_item_check';
import type { ShoppingShowOutput } from '@/contracts/shopping_show';
import { queue, resetOutbox } from '@/testing/outbox';
import { inputViolations, screenFixture } from '@/testing/schemas';
import ShoppingListView from './ShoppingListView.vue';
import ShoppingRowView from './ShoppingRowView.vue';
import ShoppingStatus from './ShoppingStatus.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const writeNowMock = vi.mocked(writeNow);
const readFreshMock = vi.mocked(readFresh);
const content = screenFixture<ShoppingShowOutput>('shopping_show');
const empty = screenFixture<ShoppingShowOutput>('shopping_show.empty');
const RANGE = { from: '2026-11-02', to: '2026-11-08' };
const MANUAL_ID = '01JBX5H0000000000000000001';

let wrapper: VueWrapper | undefined;

function show(props: { payload?: ShoppingShowOutput; pending?: boolean } = {}): VueWrapper {
    wrapper = mount(ShoppingListView, { props, attachTo: document.body });
    return wrapper;
}

function row(view: VueWrapper, name: string): VueWrapper {
    return view.findAllComponents(ShoppingRowView).find((r) => r.text().includes(name))!;
}

function tickButton(view: VueWrapper, name: string) {
    return row(view, name).get('button[data-test="tick"]');
}

async function setDate(view: VueWrapper, which: 'from' | 'to', value: string): Promise<void> {
    const field = view.get(`input[name="${which}"]`);
    await field.setValue(value);
    await field.trigger('change');
    await flushPromises();
}

async function typeItem(view: VueWrapper, value: string): Promise<void> {
    await view.get('input[name="shopping-item"]').setValue(value);
}

async function pointer(target: ReturnType<VueWrapper['find']>, type: string, clientX: number): Promise<void> {
    target.element.dispatchEvent(new MouseEvent(type, { clientX, clientY: 10, bubbles: true }));
    await flushPromises();
}

/** The list with one item the user added, under that item's id. */
function withManual(id: string, name: string): ShoppingShowOutput {
    const other = { category: 'other' as const, title: 'Other', rows: [{ key: `manual:${id}`, name, amount_text: null, is_ticked: false, is_manual: true }] };
    return { ...content, sections: [...content.sections.filter((section) => section.category !== 'other'), other] };
}

function sentIds(): string[] {
    return writeNowMock.mock.calls.map((call) => (call[1] as { id: string }).id);
}

describe('ShoppingListView', () => {
    beforeEach(async () => {
        localStorage.clear();
        await resetOutbox();
        readMock.mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({} as Awaited<ReturnType<typeof write>>);
        writeNowMock.mockReset().mockResolvedValue({});
        readFreshMock.mockReset().mockResolvedValue(content);
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('SHOP-003 lists the server\'s groups in order, each line with its name and amount to buy, without calling the server', () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Shopping list');
        expect(view.findAll('[data-test="section-title"]').map((title) => title.text())).toEqual(['Protein', 'Grains and bread', 'Other']);
        expect(row(view, 'White rice').text()).toContain('1100 g');
        expect(row(view, 'Paper towels').find('[data-test="amount"]').exists()).toBe(false);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SHOP-001 shows the dates of the list on screen', () => {
        const view = show({ payload: content });

        expect((view.get('input[name="from"]').element as HTMLInputElement).value).toBe('2026-11-02');
        expect((view.get('input[name="to"]').element as HTMLInputElement).value).toBe('2026-11-08');
        expect(view.get('input[name="to"]').attributes('min')).toBe('2026-11-02');
    });

    it('SHOP-005 a ticked line shows its tick and is struck through', () => {
        const view = show({ payload: content });

        expect(tickButton(view, 'Chicken breast').attributes('aria-pressed')).toBe('true');
        expect(row(view, 'Chicken breast').get('[data-test="name"]').classes()).toContain('line-through');
        expect(tickButton(view, 'Egg whites').attributes('aria-pressed')).toBe('false');
        expect(row(view, 'Egg whites').get('[data-test="name"]').classes()).not.toContain('line-through');
    });

    it('SHOP-001 shows the server\'s empty line when nothing is planned', () => {
        const view = show({ payload: empty });

        expect(view.findAllComponents(ShoppingRowView)).toHaveLength(0);
        expect(view.getComponent(ShoppingStatus).text()).toContain('No foods planned for these days.');
    });

    it('SHOP-001 shows no empty line while the list has items', () => {
        const view = show({ payload: { ...content, empty_text: 'No foods planned for these days.' } });

        expect(view.text()).not.toContain('No foods planned for these days.');
    });

    it('CAP-004 shows a skeleton while it reads shopping_show, then the list', async () => {
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('shopping_show', {}, expect.any(Function), expect.any(Function));
        expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(view.text()).toContain('Chicken breast');
    });

    it('CAP-004 says why the list did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('shopping_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Your shopping list could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.text()).toContain('White rice');
    });

    it('SHOP-001 picking another first day reads the list for the new dates', async () => {
        const view = show({ payload: content });

        await setDate(view, 'from', '2026-11-04');

        expect(readMock).toHaveBeenCalledWith('shopping_show', { from: '2026-11-04', to: '2026-11-08' }, expect.any(Function), expect.any(Function));
        expect(inputViolations('shopping_show', readMock.mock.calls[0]![1])).toEqual([]);
    });

    it('SHOP-001 a first day after the last moves the last day with it', async () => {
        const view = show({ payload: content });

        await setDate(view, 'from', '2026-11-10');

        expect(readMock).toHaveBeenCalledWith('shopping_show', { from: '2026-11-10', to: '2026-11-10' }, expect.any(Function), expect.any(Function));
    });

    it('SHOP-001 picking another last day reads the list for the new dates', async () => {
        const view = show({ payload: content });

        await setDate(view, 'to', '2026-11-12');

        expect(readMock).toHaveBeenCalledWith('shopping_show', { from: '2026-11-02', to: '2026-11-12' }, expect.any(Function), expect.any(Function));
    });

    it('SHOP-001 a cleared date reads nothing', async () => {
        const view = show({ payload: content });

        await setDate(view, 'to', '');

        expect(readMock).not.toHaveBeenCalled();
    });

    it('SHOP-001 a last day before the first is moved up to the first', async () => {
        const view = show({ payload: content });

        await setDate(view, 'to', '2026-11-01');

        expect(readMock).toHaveBeenCalledWith('shopping_show', { from: '2026-11-02', to: '2026-11-02' }, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 when the list for new dates does not load, the list on screen stays with its dates, and says why', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('shopping_show', 500, null));
        const view = show({ payload: content });

        await setDate(view, 'from', '2026-11-04');

        expect(view.getComponent(ShoppingStatus).get('[role="alert"]').text()).toContain('Your shopping list could not be loaded.');
        expect(row(view, 'White rice').exists()).toBe(true);
        expect((view.get('input[name="from"]').element as HTMLInputElement).value).toBe('2026-11-02');
        expect((view.get('input[name="to"]').element as HTMLInputElement).value).toBe('2026-11-08');
    });

    it('CAP-004 offline, the list for new dates says it needs a connection and the list on screen stays', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('shopping_show', 0, null));
        const view = show({ payload: content });

        await setDate(view, 'to', '2026-11-12');

        expect(view.getComponent(ShoppingStatus).get('[role="alert"]').text()).toContain('The list for those dates needs a connection.');
        expect(row(view, 'White rice').exists()).toBe(true);
        expect((view.get('input[name="to"]').element as HTMLInputElement).value).toBe('2026-11-08');
    });

    it('SHOP-001 each read keeps the dates it asked for', async () => {
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
        const view = show({ payload: content });

        await setDate(view, 'from', '2026-11-04');
        await setDate(view, 'to', '2026-11-12');

        expect(readMock.mock.calls.map((call) => call[1])).toEqual([
            { from: '2026-11-04', to: '2026-11-08' },
            { from: '2026-11-04', to: '2026-11-12' },
        ]);
    });

    it('SHOP-005 a tick made while new dates load names the list on screen', async () => {
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
        const view = show({ payload: content });

        await setDate(view, 'from', '2026-11-04');
        await tickButton(view, 'Egg whites').trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith('shopping_item_check', expect.objectContaining(RANGE), expect.anything());
    });

    it('SHOP-005 tapping a line ticks it off through the outbox, with the dates of the list', async () => {
        const view = show({ payload: content });

        await tickButton(view, 'Egg whites').trigger('click');
        await flushPromises();

        const input = { ...RANGE, key: 'food:01JBX5F0000000000000000007', ticked: true } satisfies ShoppingItemCheckInput;
        expect(writeMock).toHaveBeenCalledWith('shopping_item_check', input, { label: 'Ticked Egg whites off the shopping list' });
        expect(inputViolations('shopping_item_check', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(writeNowMock).not.toHaveBeenCalled();
    });

    it('SHOP-005 tapping a ticked line takes the tick back', async () => {
        const view = show({ payload: content });

        await tickButton(view, 'Chicken breast').trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith(
            'shopping_item_check',
            { ...RANGE, key: 'food:01JBX5F0000000000000000002', ticked: false },
            { label: 'Took the tick off Chicken breast on the shopping list' },
        );
    });

    it('OFF-009 a tick waiting to sync shows on its line as tapped, with a pending mark', async () => {
        await queue({ name: 'shopping_item_check', input: { ...RANGE, key: 'food:01JBX5F0000000000000000007', ticked: true }, affects: ['shopping_show'], label: 'Ticked Egg whites off the shopping list' });
        const view = show({ payload: content });
        await flushPromises();

        expect(tickButton(view, 'Egg whites').attributes('aria-pressed')).toBe('true');
        expect(row(view, 'Egg whites').text()).toContain('Waiting to sync');
        expect(row(view, 'White rice').text()).not.toContain('Waiting to sync');
    });

    it('OFF-009 a waiting tick on other dates leaves this list\'s line as the server has it', async () => {
        await queue({ name: 'shopping_item_check', input: { from: '2026-11-09', to: '2026-11-15', key: 'food:01JBX5F0000000000000000007', ticked: true }, label: 'Ticked Egg whites off the shopping list' });
        const view = show({ payload: content });
        await flushPromises();

        expect(tickButton(view, 'Egg whites').attributes('aria-pressed')).toBe('false');
    });

    it('OFF-009 tapping a line whose tick waits to sync takes that tick back', async () => {
        await queue({ name: 'shopping_item_check', input: { ...RANGE, key: 'food:01JBX5F0000000000000000007', ticked: true }, affects: ['shopping_show'], label: 'Ticked Egg whites off the shopping list' });
        const view = show({ payload: content });
        await flushPromises();

        await tickButton(view, 'Egg whites').trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith(
            'shopping_item_check',
            { ...RANGE, key: 'food:01JBX5F0000000000000000007', ticked: false },
            { label: 'Took the tick off Egg whites on the shopping list' },
        );
    });

    it('OFF-009 a tick the server took stays on its line until the list is read again', async () => {
        writeMock.mockResolvedValueOnce({ seq: 9999 } as OutboxEntry);
        const view = show({ payload: content });

        await tickButton(view, 'Egg whites').trigger('click');
        await flushPromises();

        expect(tickButton(view, 'Egg whites').attributes('aria-pressed')).toBe('true');
        expect(row(view, 'Egg whites').text()).not.toContain('Waiting to sync');

        await setDate(view, 'to', '2026-11-12');
        expect(tickButton(view, 'Egg whites').attributes('aria-pressed')).toBe('false');
    });

    it('OFF-009 a tick the server refused shows the server\'s tick again', async () => {
        const refused = await queue({
            name: 'shopping_item_check',
            input: { ...RANGE, key: 'food:01JBX5F0000000000000000007', ticked: true },
            label: 'Ticked Egg whites off the shopping list',
            refusal: { status: 422, body: null },
        });
        writeMock.mockResolvedValueOnce(refused);
        const view = show({ payload: content });

        await tickButton(view, 'Egg whites').trigger('click');
        await flushPromises();

        expect(tickButton(view, 'Egg whites').attributes('aria-pressed')).toBe('false');
    });

    it('CAP-012 says why a tick could not be kept', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('shopping_item_check', 401, null));
        const view = show({ payload: content });

        await tickButton(view, 'Egg whites').trigger('click');
        await flushPromises();

        expect(view.getComponent(ShoppingStatus).get('[role="alert"]').text()).toContain('The item could not be ticked. Try again.');
    });

    it('OFF-008 marks each amount to buy as waiting to sync', () => {
        const view = show({ payload: content, pending: true });

        const amounts = view.findAllComponents(ShoppingRowView).filter((r) => r.find('[data-test="amount"]').exists());
        expect(amounts).toHaveLength(3);
        expect(amounts.every((r) => r.get('[data-test="amount"]').text().includes('Waiting to sync'))).toBe(true);
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        expect(show({ payload: content }).text()).not.toContain('Waiting to sync');
    });

    it('SHOP-005 adds a typed item with a new id and the dates of the list, then clears the field', async () => {
        const view = show({ payload: content });

        await typeItem(view, '  Bin bags ');
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();

        expect(writeNowMock).toHaveBeenCalledWith(
            'shopping_item_add',
            { id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), text: 'Bin bags', ...RANGE },
            { idempotencyKey: expect.stringMatching(/^shopping_item_add:/) },
        );
        expect(inputViolations('shopping_item_add', writeNowMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('shopping_show', {}, expect.any(Function), expect.any(Function));
        expect((view.get('input[name="shopping-item"]').element as HTMLInputElement).value).toBe('');
    });

    it('SHOP-005 Return in the field adds the item', async () => {
        const view = show({ payload: content });

        await typeItem(view, 'Bin bags');
        await view.get('form[data-test="add-item"]').trigger('submit');
        await flushPromises();

        expect(writeNowMock).toHaveBeenCalledWith('shopping_item_add', expect.objectContaining({ text: 'Bin bags' }), expect.anything());
    });

    it('SHOP-005 a blank item cannot be added', async () => {
        const view = show({ payload: content });

        await typeItem(view, '   ');
        await view.get('form[data-test="add-item"]').trigger('submit');
        await flushPromises();

        expect(view.get('button[aria-label="Add item"]').attributes('disabled')).toBeDefined();
        expect(writeNowMock).not.toHaveBeenCalled();
    });

    it('CAP-012 a refused add keeps the typed item and says why', async () => {
        writeNowMock.mockRejectedValueOnce(new CapabilityError('shopping_item_add', 422, { error: { message: 'An item can be up to 255 characters.' } }));
        const view = show({ payload: content });

        await typeItem(view, 'Bin bags');
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();

        expect(view.getComponent(ShoppingStatus).get('[role="alert"]').text()).toContain('An item can be up to 255 characters.');
        expect((view.get('input[name="shopping-item"]').element as HTMLInputElement).value).toBe('Bin bags');
    });

    it('CAP-019 an add that got no answer is sent again with the same id', async () => {
        writeNowMock.mockRejectedValueOnce(new CapabilityError('shopping_item_add', 504, null));
        const view = show({ payload: content });

        await typeItem(view, 'Bin bags');
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();

        const [first, second] = sentIds();
        expect(second).toBe(first);
    });

    it('CAP-019 an add that got no answer but is on the server\'s list is done: the field clears and the next item takes a new id', async () => {
        writeNowMock.mockRejectedValueOnce(new CapabilityError('shopping_item_add', 504, null));
        readFreshMock.mockImplementation(async () => withManual(sentIds()[0]!, 'Bin bags'));
        const view = show({ payload: content });

        await typeItem(view, 'Bin bags');
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();

        expect((view.get('input[name="shopping-item"]').element as HTMLInputElement).value).toBe('');
        await typeItem(view, 'Milk');
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();

        const [first, second] = sentIds();
        expect(second).not.toBe(first);
    });

    it('CAP-019 a refused add takes a new id next time', async () => {
        writeNowMock.mockRejectedValueOnce(new CapabilityError('shopping_item_add', 422, { error: { message: 'No.' } }));
        const view = show({ payload: content });

        await typeItem(view, 'Bin bags');
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();
        await view.get('button[aria-label="Add item"]').trigger('click');
        await flushPromises();

        const [first, second] = sentIds();
        expect(second).not.toBe(first);
    });

    it('CAP-019 the next item takes a new id once one is added', async () => {
        const view = show({ payload: content });

        for (const text of ['Bin bags', 'Foil']) {
            await typeItem(view, text);
            await view.get('button[aria-label="Add item"]').trigger('click');
            await flushPromises();
        }

        const [first, second] = writeNowMock.mock.calls.map((call) => (call[1] as { id: string }).id);
        expect(first).not.toBe(second);
    });

    it('SHOP-005 an added item can be deleted, with the dates of the list; a planned food cannot', async () => {
        const view = show({ payload: content });

        expect(row(view, 'Egg whites').find('[data-test="delete"]').exists()).toBe(false);
        await row(view, 'Paper towels').get('[data-test="delete"]').trigger('click');
        await flushPromises();

        expect(writeNowMock).toHaveBeenCalledWith(
            'shopping_item_delete',
            { id: MANUAL_ID, ...RANGE },
            { idempotencyKey: expect.stringMatching(/^shopping_item_delete:/) },
        );
        expect(inputViolations('shopping_item_delete', writeNowMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('shopping_show', {}, expect.any(Function), expect.any(Function));
    });

    it('UI-008 a swipe on an added item shows Delete; a tap on the line hides it without ticking', async () => {
        const view = show({ payload: content });
        const swipe = row(view, 'Paper towels').get('[data-test="swipe"]');

        await pointer(swipe, 'pointerdown', 300);
        await pointer(swipe, 'pointermove', 200);
        await pointer(swipe, 'pointerup', 200);
        expect(swipe.attributes('style')).toContain('translateX(-96px)');

        await pointer(swipe, 'pointerdown', 200);
        await pointer(swipe, 'pointerup', 200);
        await tickButton(view, 'Paper towels').trigger('click');
        await flushPromises();

        expect(swipe.attributes('style')).toContain('translateX(0px)');
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('UI-008 a planned food does not swipe', async () => {
        const view = show({ payload: content });
        const swipe = row(view, 'Egg whites').get('[data-test="swipe"]');

        await pointer(swipe, 'pointerdown', 300);
        await pointer(swipe, 'pointermove', 200);
        await pointer(swipe, 'pointerup', 200);

        expect(swipe.attributes('style')).toContain('translateX(0px)');
    });

    it('CAP-012 says why a delete was refused', async () => {
        writeNowMock.mockRejectedValueOnce(new CapabilityError('shopping_item_delete', 404, { error: { message: 'That item is gone.' } }));
        const view = show({ payload: content });

        await row(view, 'Paper towels').get('[data-test="delete"]').trigger('click');
        await flushPromises();

        expect(view.getComponent(ShoppingStatus).get('[role="alert"]').text()).toContain('That item is gone.');
    });

    it('SHOP-005 Share hands the server\'s list text to the share sheet', async () => {
        const share = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { ...navigator, share });
        const view = show({ payload: content });

        await view.get('button[aria-label="Share"]').trigger('click');
        await flushPromises();

        expect(share).toHaveBeenCalledWith({ title: 'Shopping list', text: content.share_text });
    });

    it('SHOP-005 where the browser cannot share, Share copies the list and says so', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { ...navigator, share: undefined, clipboard: { writeText } });
        const view = show({ payload: content });

        await view.get('button[aria-label="Share"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith(content.share_text);
        expect(view.get('[role="status"]').text()).toContain('Copied');
    });

    it('SHOP-005 there is nothing to share on an empty list', () => {
        const view = show({ payload: empty });

        expect(view.get('button[aria-label="Share"]').attributes('disabled')).toBeDefined();
    });
});

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import { CapabilityError, read, readFresh, writeNow } from '@/app/call';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import MesocycleView from './MesocycleView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
const push = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ push }) }));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);
const writeMock = vi.mocked(writeNow);
const meso = screenFixture<MesocycleShowOutput>('mesocycle_show');

function show(props: Record<string, unknown> = { payload: meso }) {
    return mount(MesocycleView, { props, global: { stubs: { RouterLink: RouterLinkStub, teleport: true } } });
}

async function openMenu(wrapper: ReturnType<typeof show>, item: string): Promise<void> {
    await wrapper.find('[data-test="menu"]').trigger('click');
    await wrapper.find(`[data-test="item-${item}"]`).trigger('click');
}

// The capability and input of the last write; its idempotency key is checked on its own.
function lastWrite(): [string, Record<string, unknown>] {
    return writeMock.mock.calls.at(-1)!.slice(0, 2) as [string, Record<string, unknown>];
}

describe('MesocycleView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(meso);
        readFreshMock.mockReset().mockResolvedValue(meso);
        writeMock.mockReset().mockResolvedValue({});
        push.mockReset();
    });

    it('MESO-040 shows a column per week with sets per muscle and each day\'s exercises', () => {
        const wrapper = show();

        expect(wrapper.find('h1').text()).toBe('Meso 2');
        const columns = wrapper.findAll('section[aria-label]');
        expect(columns.map((c) => c.attributes('aria-label'))).toEqual(meso.saved!.weeks.map((w) => w.title));
        expect(columns[0]!.text()).toContain('Sets per muscle');
        expect(columns[0]!.text()).toContain('Chest6');
        expect(columns[0]!.text()).toContain('Day 1 · Upper A');
        expect(columns[0]!.text()).toContain('Bench Press3');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MESO-041 shows the phase, each week\'s calories, the current week and the diet break', () => {
        const wrapper = show();
        const columns = wrapper.findAll('section[aria-label]');

        expect(wrapper.text()).toContain('Fat loss');
        expect(wrapper.text()).toContain('6 weeks · week 4 now');
        expect(columns[3]!.attributes('data-now')).toBe('true');
        expect(columns[3]!.text()).toContain('Now');
        expect(columns[4]!.text()).toContain('Diet break');
        expect(columns[4]!.text()).toContain('2,600 kcal/day');
    });

    it('MESO-040 opens a day\'s workout, and a day that cannot open is not a link', async () => {
        const dropped = structuredClone(meso);
        dropped.saved!.weeks[5]!.days[2] = { ...dropped.saved!.weeks[5]!.days[2]!, state: 'not_planned', can_open: false };
        const wrapper = show({ payload: dropped });

        const links = wrapper.findAllComponents(RouterLinkStub).filter((l) => String(l.props('to')).startsWith('/workouts/'));
        expect(links[0]!.props('to')).toBe(`/workouts/${meso.id}/1/1`);
        expect(links).toHaveLength(17);
        expect(wrapper.findAll('[data-test="day"]').at(-1)!.attributes('aria-disabled')).toBe('true');
        expect(wrapper.findAll('[data-test="day"]').at(-1)!.text()).toContain('Not planned');
    });

    it('MESO-042 changes the length with + and −, sending a valid mesocycle_length_set with the length shown', async () => {
        // The read after the + answers 7 weeks, so the − that follows expects 7.
        readMock.mockResolvedValue({ ...meso, saved: { ...meso.saved!, weeks_count: 7 } });
        const wrapper = show();

        await wrapper.find('[data-test="lengthen"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_length_set', { mesocycle_id: meso.id, weeks: 7, expected_weeks: 6 }]);
        expect(inputViolations(...lastWrite())).toEqual([]);

        await wrapper.find('[data-test="shorten"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_length_set', { mesocycle_id: meso.id, weeks: 6, expected_weeks: 7 }]);
        expect(inputViolations(...lastWrite())).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: meso.id }, expect.any(Function), expect.any(Function));
    });

    it('MESO-042 reads the server\'s length again when a step is refused, so the next step expects it', async () => {
        const moved = "This mesocycle's length changed to 7 weeks since the screen was loaded, so nothing was changed.";
        readFreshMock.mockResolvedValue({ ...meso, saved: { ...meso.saved!, weeks_count: 7 } });
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_length_set', 422, { error: { message: moved } }));
        const wrapper = show();

        await wrapper.find('[data-test="shorten"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_length_set', { mesocycle_id: meso.id, weeks: 5, expected_weeks: 6 }]);
        expect(wrapper.find('[data-test="error"]').text()).toContain(moved);
        expect(readFreshMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: meso.id }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[aria-label="7 weeks"]').exists()).toBe(true);

        await wrapper.find('[data-test="shorten"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_length_set', { mesocycle_id: meso.id, weeks: 6, expected_weeks: 7 }]);
    });

    it('CAP-010 locks the stepper and the menu while a write is in flight, so a second tap sends nothing', async () => {
        let answer: (value: unknown) => void = () => {};
        writeMock.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
        const wrapper = show();

        await wrapper.find('[data-test="lengthen"]').trigger('click');
        for (const control of ['lengthen', 'shorten', 'menu']) {
            expect(wrapper.find(`[data-test="${control}"]`).attributes('disabled')).toBeDefined();
        }
        await wrapper.find('[data-test="lengthen"]').trigger('click');
        await wrapper.find('[data-test="shorten"]').trigger('click');
        expect(writeMock).toHaveBeenCalledTimes(1);

        answer({});
        await flushPromises();
        for (const control of ['lengthen', 'shorten', 'menu']) {
            expect(wrapper.find(`[data-test="${control}"]`).attributes('disabled')).toBeUndefined();
        }
    });

    it('MESO-042 turns off + and − when the server says the length cannot change', () => {
        const fixed = structuredClone(meso);
        fixed.saved = { ...fixed.saved!, can_shorten: false, can_lengthen: false };
        const wrapper = show({ payload: fixed });

        expect(wrapper.find('[data-test="shorten"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="lengthen"]').attributes('disabled')).toBeDefined();
    });

    it('MESO-043 MESO-044 ends early after the deload warning, offering a diet break in fat loss', async () => {
        const wrapper = show();
        await openMenu(wrapper, 'end');

        const dialog = wrapper.find('[data-test="end-dialog"]');
        expect(dialog.text()).toContain('Skipping the deload is not recommended for intermediate and advanced lifters.');
        expect(dialog.text()).toContain('Start a diet break next week?');

        await wrapper.find('[data-test="end-with-break"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_end', { mesocycle_id: meso.id, diet_break: true }]);
        expect(inputViolations(...lastWrite())).toEqual([]);
    });

    it('MESO-044 ends without a diet break when the user declines the offer', async () => {
        const wrapper = show();
        await openMenu(wrapper, 'end');

        await wrapper.find('[data-test="end-without-break"]').trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledTimes(1);
        expect(lastWrite()).toEqual(['mesocycle_end', { mesocycle_id: meso.id, diet_break: false }]);
        expect(inputViolations(...lastWrite())).toEqual([]);
    });

    it('MESO-043 ends without a diet break outside fat loss, and Keep going changes nothing', async () => {
        const gain = structuredClone(meso);
        gain.saved!.end = { warning: 'Skipping the deload is not recommended for intermediate and advanced lifters.', diet_break_offer: null };
        const wrapper = show({ payload: gain });
        await openMenu(wrapper, 'end');

        expect(wrapper.find('[data-test="end-with-break"]').exists()).toBe(false);
        await wrapper.find('[data-cancel]').trigger('click');
        expect(wrapper.find('[data-test="end-dialog"]').exists()).toBe(false);
        expect(writeMock).not.toHaveBeenCalled();

        await openMenu(wrapper, 'end');
        expect(wrapper.find('[data-save]').text()).toBe('End meso');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_end', { mesocycle_id: meso.id, diet_break: false }]);
    });

    it('MESO-045 adds a day with a client ULID, sending a valid mesocycle_day_add', async () => {
        const wrapper = show();
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const [name, input] = lastWrite();
        expect(name).toBe('mesocycle_day_add');
        expect(input).toMatchObject({ mesocycle_id: meso.id, day_id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/) });
        expect(inputViolations(name, input)).toEqual([]);
    });

    it('CAP-019 sends the same day_id again after Add day got no answer, and a new one once the server answered', async () => {
        writeMock
            .mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'network' }))
            .mockResolvedValueOnce({})
            .mockResolvedValueOnce({});
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();
        await openMenu(wrapper, 'add-day');
        await flushPromises();
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const ids = writeMock.mock.calls.map(([, input]) => (input as { day_id: string }).day_id);
        expect(ids[1]).toBe(ids[0]);
        expect(ids[2]).not.toBe(ids[1]);
    });

    it('CAP-019 CAP-014 starts a new day_id after the app\'s own 500, a final answer', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 500, { ok: false, error: { message: 'Server error.' } }));
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();
        expect(wrapper.find('[data-test="error"]').text()).toContain('Server error.');
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const ids = writeMock.mock.calls.map(([, input]) => (input as { day_id: string }).day_id);
        expect(ids[1]).not.toBe(ids[0]);
    });

    it('CAP-019 starts a new day_id after the server refused one', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 422, { error: { message: 'No room for another day.' } }));
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const ids = writeMock.mock.calls.map(([, input]) => (input as { day_id: string }).day_id);
        expect(ids[1]).not.toBe(ids[0]);
    });

    it('OFF-003 reads the server\'s mesocycle again after a write timed out, and says it may have worked', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' }));
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();

        expect(readFreshMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: meso.id });
        expect(wrapper.find('[data-test="error"]').text()).toContain('may have worked');
    });

    it('MESO-045 MESO-043 turns off Add day and End meso when the server says they cannot happen', async () => {
        const locked = structuredClone(meso);
        locked.saved = { ...locked.saved!, can_add_day: false, end: null };
        const wrapper = show({ payload: locked });
        await wrapper.find('[data-test="menu"]').trigger('click');

        expect(wrapper.find('[data-test="item-add-day"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="item-end"]').attributes('disabled')).toBeDefined();
        await wrapper.find('[data-test="item-end"]').trigger('click');
        expect(wrapper.find('[data-test="end-dialog"]').exists()).toBe(false);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('MESO-032 opens Progression rates from the menu', async () => {
        const wrapper = show();
        await openMenu(wrapper, 'rates');

        expect(push).toHaveBeenCalledWith(`/train/mesocycles/${meso.id}/rates`);
    });

    it('CAP-012 shows the server\'s words when a write fails', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_length_set', 422, { error: { message: 'A mesocycle runs at most 8 weeks.' } }));
        const wrapper = show();

        await wrapper.find('[data-test="lengthen"]').trigger('click');
        await flushPromises();

        const alert = wrapper.find('[data-test="error"]');
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain('Something went wrong.');
        expect(alert.text()).toContain('A mesocycle runs at most 8 weeks.');
        await alert.find('button').trigger('click');
        expect(wrapper.find('[data-test="error"]').exists()).toBe(false);
    });

    it('OFF-008 marks the server\'s numbers waiting to sync with PendingMark, one mark per week, and dims them', () => {
        const wrapper = show({ payload: meso, pending: true });

        // The length line, then each week's calorie line.
        expect(wrapper.findAll('[data-mark]')).toHaveLength(1 + meso.saved!.weeks.length);
        expect(wrapper.findAll('[data-value]').map((n) => n.text())).toEqual(expect.arrayContaining(['6 weeks · week 4 now', '2,350 kcal/day']));
        expect(wrapper.findAll('[data-value]').every((n) => n.classes('opacity-60'))).toBe(true);
        // The numbers under each mark dim the same way: the week count, the set counts.
        const dimmed = wrapper.findAll('.opacity-60:not([data-value])').map((n) => n.text());
        expect(dimmed).toContain('6');
        expect(dimmed.length).toBeGreaterThan(meso.saved!.weeks.length);
    });

    it('OFF-008 shows no mark and dims nothing when nothing waits to sync', () => {
        const wrapper = show();

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
        expect(wrapper.find('.opacity-60').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Waiting to sync');
    });

    it('MESO-040 brings the current week into view once, not again after each read', async () => {
        const scrolls = vi.fn();
        const original = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = scrolls;
        onTestFinished(() => {
            Element.prototype.scrollIntoView = original;
        });
        const wrapper = show({ mesocycleId: meso.id });
        await flushPromises();
        expect(scrolls).toHaveBeenCalledTimes(1);

        await wrapper.find('[data-test="lengthen"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(scrolls).toHaveBeenCalledTimes(1);
    });

    it('CAP-019 starts a new day_id when the server\'s answer after a timed-out Add day shows the day, whatever the cached copy says', async () => {
        const added = structuredClone(meso);
        for (const week of added.saved!.weeks) week.days.push({ ...week.days[0]!, day: 4, title: 'Day 4 · New', label: 'New', exercises: [] });
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' })).mockResolvedValueOnce({});
        readFreshMock.mockResolvedValueOnce(added);
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const ids = writeMock.mock.calls.map(([, input]) => (input as { day_id: string }).day_id);
        expect(ids[1]).not.toBe(ids[0]);
    });

    it.each([
        ['a gateway\'s 504', new CapabilityError('mesocycle_day_add', 504, null)],
        ['the app\'s retryable 409', new CapabilityError('mesocycle_day_add', 409, { ok: false, error: { message: 'Still working.', retryable: true } })],
    ])('CAP-019 CAP-014 sends the same day_id again after %s, which may have added the day', async (_label, failure) => {
        writeMock.mockRejectedValueOnce(failure).mockResolvedValueOnce({});
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();
        expect(wrapper.find('[data-test="error"]').text()).toContain('may have worked');
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const ids = writeMock.mock.calls.map(([, input]) => (input as { day_id: string }).day_id);
        expect(ids[1]).toBe(ids[0]);
    });

    it('CAP-019 keeps the day_id when the server\'s answer could not be read after a timed-out Add day', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' })).mockResolvedValueOnce({});
        readFreshMock.mockRejectedValueOnce(new CapabilityError('mesocycle_show', 0, { reason: 'timeout' }));
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();
        expect(wrapper.text()).toContain('Week 4');
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const ids = writeMock.mock.calls.map(([, input]) => (input as { day_id: string }).day_id);
        expect(ids[1]).toBe(ids[0]);
    });

    it('CAP-019 keeps the day_id when the read after a timed-out Add day does not show the day yet', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_day_add', 0, { reason: 'timeout' })).mockResolvedValueOnce({});
        const wrapper = show();

        await openMenu(wrapper, 'add-day');
        await flushPromises();
        await openMenu(wrapper, 'add-day');
        await flushPromises();

        const ids = writeMock.mock.calls.map(([, input]) => (input as { day_id: string }).day_id);
        expect(ids[1]).toBe(ids[0]);
    });

    it('CAP-002 reads mesocycle_show for the id it is opened with, showing a skeleton meanwhile', async () => {
        const wrapper = show({ mesocycleId: meso.id });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);

        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: meso.id }, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toContain('Week 4');
    });

    it('CAP-004 says when the mesocycle could not load, and tries again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('mesocycle_show', 500, null));
        const wrapper = show({ mesocycleId: meso.id });
        await flushPromises();

        expect(wrapper.text()).toContain('The mesocycle could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Week 4');
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('mesocycle_show', 401, null));
        const wrapper = show({ mesocycleId: meso.id });
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });

    it('MESO-007 says a draft is still being built (mesocycle_show.empty)', () => {
        const wrapper = show({ payload: screenFixture<MesocycleShowOutput>('mesocycle_show.empty') });

        expect(wrapper.text()).toContain('This mesocycle is still being built.');
        expect(wrapper.find('[data-test="menu"]').attributes('disabled')).toBeDefined();
    });
});

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import MesocycleView from './MesocycleView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const meso = screenFixture<MesocycleShowOutput>('mesocycle_show');

function show(props: Record<string, unknown> = { payload: meso }) {
    return mount(MesocycleView, { props, global: { stubs: { RouterLink: RouterLinkStub } } });
}

function lastWrite(): [string, Record<string, unknown>] {
    return writeMock.mock.calls.at(-1) as [string, Record<string, unknown>];
}

describe('MesocycleView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(meso);
        writeMock.mockReset().mockResolvedValue({});
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

    it('MESO-042 changes the length with + and −, sending a valid mesocycle_length_set', async () => {
        const wrapper = show();

        await wrapper.find('[data-test="lengthen"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_length_set', { mesocycle_id: meso.id, weeks: 7 }]);
        expect(inputViolations(...lastWrite())).toEqual([]);

        await wrapper.find('[data-test="shorten"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_length_set', { mesocycle_id: meso.id, weeks: 5 }]);
        expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: meso.id }, expect.any(Function), expect.any(Function));
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
        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="end"]').trigger('click');

        const dialog = wrapper.find('[data-test="end-dialog"]');
        expect(dialog.text()).toContain('Skipping the deload is not recommended for intermediate and advanced lifters.');
        expect(dialog.text()).toContain('Start a diet break next week?');

        await wrapper.find('[data-test="end-with-break"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_end', { mesocycle_id: meso.id, diet_break: true }]);
        expect(inputViolations(...lastWrite())).toEqual([]);
    });

    it('MESO-043 ends without a diet break outside fat loss, and Keep going changes nothing', async () => {
        const gain = structuredClone(meso);
        gain.saved!.end = { warning: 'Skipping the deload is not recommended for intermediate and advanced lifters.', diet_break_offer: null };
        const wrapper = show({ payload: gain });
        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="end"]').trigger('click');

        expect(wrapper.find('[data-test="end-with-break"]').exists()).toBe(false);
        await wrapper.find('[data-test="keep-going"]').trigger('click');
        expect(writeMock).not.toHaveBeenCalled();

        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="end"]').trigger('click');
        await wrapper.find('[data-test="end-confirm"]').trigger('click');
        await flushPromises();
        expect(lastWrite()).toEqual(['mesocycle_end', { mesocycle_id: meso.id, diet_break: false }]);
    });

    it('MESO-045 adds a day with a client ULID, sending a valid mesocycle_day_add', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="add-day"]').trigger('click');
        await flushPromises();

        const [name, input] = lastWrite();
        expect(name).toBe('mesocycle_day_add');
        expect(input).toMatchObject({ mesocycle_id: meso.id, day_id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/) });
        expect(inputViolations(name, input)).toEqual([]);
    });

    it('MESO-032 links the menu to Progression rates', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="menu"]').trigger('click');

        expect(wrapper.find('[data-test="rates"]').findComponent(RouterLinkStub).props('to')).toBe(`/train/mesocycles/${meso.id}/rates`);
    });

    it('CAP-012 shows the server\'s words when a write fails', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_length_set', 422, { error: { message: 'A mesocycle runs at most 8 weeks.' } }));
        const wrapper = show();

        await wrapper.find('[data-test="lengthen"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Something went wrong');
        expect(wrapper.text()).toContain('A mesocycle runs at most 8 weeks.');
    });

    it('OFF-008 dims the server\'s numbers and says they are waiting to sync', () => {
        const wrapper = show({ payload: meso, pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
        expect(wrapper.findAll('.opacity-50').map((n) => n.text())).toEqual(expect.arrayContaining(['6 weeks · week 4 now', '2,350 kcal/day', '6']));
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

    it('MESO-007 says a draft is still being built (mesocycle_show.empty)', () => {
        const wrapper = show({ payload: screenFixture<MesocycleShowOutput>('mesocycle_show.empty') });

        expect(wrapper.text()).toContain('This mesocycle is still being built.');
        expect(wrapper.find('[data-test="menu"]').attributes('disabled')).toBeDefined();
    });
});

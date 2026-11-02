import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import PriorityBarsView from './PriorityBarsView.vue';
import PriorityRowView from './PriorityRowView.vue';
import ProgressionRatesView from './ProgressionRatesView.vue';
import WarningLabel from './WarningLabel.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);
const writeMock = vi.mocked(write);
const meso = screenFixture<MesocycleShowOutput>('mesocycle_show');

async function pick(wrapper: ReturnType<typeof mount>, muscle: string, title: string): Promise<void> {
    const select = wrapper.find(`select[aria-label="${muscle} priority"]`);
    const index = meso.priority_options.findIndex((o) => o.title === title);
    (select.element as HTMLSelectElement).selectedIndex = index;
    await select.trigger('change');
    await flushPromises();
}

describe('ProgressionRatesView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(meso);
        readFreshMock.mockReset().mockResolvedValue(meso);
        writeMock.mockReset().mockResolvedValue({});
    });

    it('MESO-033 lists all fourteen muscle groups with their priority', () => {
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });
        const rows = wrapper.findAllComponents(PriorityRowView);

        expect(rows).toHaveLength(14);
        expect(rows[0]!.text()).toContain('Chest');
        expect(rows[0]!.text()).toContain('Emphasize');
        expect(rows.at(-1)!.text()).toContain('Forearms');
        expect(rows.at(-1)!.text()).toContain('Off');
        expect(wrapper.text()).toContain('A change applies from your next session.');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MESO-032 changes a priority with a valid priority_set and reads the mesocycle again', async () => {
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });

        await pick(wrapper, 'Chest', 'Grow');

        expect(writeMock).toHaveBeenCalledWith('priority_set', { mesocycle_id: meso.id, muscle: 'chest', priority: 'grow' });
        expect(inputViolations('priority_set', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: meso.id }, expect.any(Function), expect.any(Function));
    });

    it('MESO-034 sets a muscle Off with a null priority', async () => {
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });

        await pick(wrapper, 'Back', 'Off');

        expect(writeMock).toHaveBeenCalledWith('priority_set', { mesocycle_id: meso.id, muscle: 'back', priority: null });
        expect(inputViolations('priority_set', writeMock.mock.calls[0]![1])).toEqual([]);
    });

    it('MESO-032 sends nothing when the same priority is picked', async () => {
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });

        await pick(wrapper, 'Chest', 'Emphasize');

        expect(writeMock).not.toHaveBeenCalled();
    });

    it('CAP-010 turns off every picker while a priority is saving, and back on once it is saved', async () => {
        let answer: (value: unknown) => void = () => {};
        writeMock.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });

        await pick(wrapper, 'Chest', 'Grow');
        expect(wrapper.findAll('select').every((s) => s.attributes('disabled') !== undefined)).toBe(true);
        await pick(wrapper, 'Back', 'Off');
        expect(writeMock).toHaveBeenCalledTimes(1);

        answer({});
        await flushPromises();
        expect(wrapper.findAll('select').every((s) => s.attributes('disabled') === undefined)).toBe(true);
    });

    it('OFF-003 reads the server\'s priorities again when the save timed out', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('priority_set', 0, { reason: 'timeout' }));
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });

        await pick(wrapper, 'Chest', 'Grow');

        expect(readFreshMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: meso.id });
        expect(wrapper.find('[role="alert"]').text()).toContain('may have worked');
    });

    it('MESO-032 keeps showing the saved priority after a failed save, so the same pick sends again', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('priority_set', 422, { error: { message: 'Try again.' } })).mockResolvedValueOnce({});
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });

        await pick(wrapper, 'Chest', 'Grow');
        const chest = wrapper.find('select[aria-label="Chest priority"]').element as HTMLSelectElement;
        expect(chest.options[chest.selectedIndex]!.text).toBe('Emphasize');

        await pick(wrapper, 'Chest', 'Grow');
        expect(writeMock).toHaveBeenCalledTimes(2);
    });

    it('CAP-012 shows why a priority was not saved', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('priority_set', 422, { error: { message: 'This mesocycle has ended.' } }));
        const wrapper = mount(ProgressionRatesView, { props: { payload: meso } });

        await pick(wrapper, 'Chest', 'Grow');

        expect(wrapper.find('[role="alert"]').text()).toBe('This mesocycle has ended.');
    });

    it('CAP-002 reads the mesocycle it is opened for, with a skeleton, then an error with Try again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('mesocycle_show', 500, null));
        const wrapper = mount(ProgressionRatesView, { props: { mesocycleId: meso.id } });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('The mesocycle could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAllComponents(PriorityRowView)).toHaveLength(14);
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('mesocycle_show', 401, null));
        const wrapper = mount(ProgressionRatesView, { props: { mesocycleId: meso.id } });
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });

    it('MESO-007 has nothing to change on a draft (mesocycle_show.empty)', () => {
        const wrapper = mount(ProgressionRatesView, { props: { payload: screenFixture<MesocycleShowOutput>('mesocycle_show.empty') } });

        expect(wrapper.text()).toContain('This mesocycle is still being built.');
    });
});

describe('PriorityViews', () => {
    it.each([
        [3, 3],
        [2, 2],
        [1, 1],
        [0, 0],
    ])('MESO-030 fills %i of three bars', (bars, filled) => {
        const wrapper = mount(PriorityBarsView, { props: { bars } });

        expect(wrapper.findAll('[data-test="bar"]')).toHaveLength(3);
        expect(wrapper.findAll('.bg-accent')).toHaveLength(filled);
        expect(wrapper.attributes('aria-label')).toBe(`${bars} of 3 bars`);
    });

    it('MESO-032 a row offers every priority and turns off while saving', () => {
        const wrapper = mount(PriorityRowView, {
            props: { muscleTitle: 'Chest', priority: 'grow', priorityTitle: 'Grow', bars: 2, options: meso.priority_options, disabled: true },
        });

        expect(wrapper.findAll('option').map((o) => o.text())).toEqual(['Emphasize', 'Grow', 'Maintain', 'Off']);
        expect(wrapper.find('select').attributes('disabled')).toBeDefined();
    });

    it('MESO-021 shows a warning that does not block', () => {
        const wrapper = mount(WarningLabel, { props: { text: 'More than two Emphasize muscles slows recovery.' } });

        expect(wrapper.text()).toBe('More than two Emphasize muscles slows recovery.');
        expect(wrapper.find('button').exists()).toBe(false);
    });
});

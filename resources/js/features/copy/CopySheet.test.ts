import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { CopyShowOutput } from '@/contracts/copy_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import CopySheet from './CopySheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const foods = screenFixture<CopyShowOutput>('copy_show');
const day = screenFixture<CopyShowOutput>('copy_show.day');
const SOURCE_MEAL = '01JBX0M1N2P3Q4R5S6T7V8W9Z9';

// The sheets teleport to <body>; render them in place.
const global = { stubs: { teleport: true } };

function show(props: Record<string, unknown> = { payload: foods, mealId: SOURCE_MEAL }) {
    return mount(CopySheet, { props, global });
}

function sent(capability: string): Record<string, unknown> {
    const call = writeMock.mock.calls.filter(([name]) => name === capability).at(-1)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

const save = (wrapper: ReturnType<typeof show>) => wrapper.findAll('[role="dialog"]')[0]!.get('[data-save]');
const strip = (wrapper: ReturnType<typeof show>) => wrapper.get('[role="group"][aria-label="Copy to"]');

describe('CopySheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(foods);
        writeMock.mockReset().mockResolvedValue({});
    });

    it('MEAL-060 shows the meal copied from, the week strip and the shown day\'s meals around its workout', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Copy foods');
        const source = wrapper.get('[data-test="source"]');
        expect(source.text()).toContain('From Breakfast');
        expect(source.text()).toContain('8:00 AM');
        expect(source.text()).toContain('Targets met');
        expect(source.text()).toContain('Oats 80 g · Whey protein 30 g');
        expect(wrapper.text()).toContain('Copy to meals');
        expect(wrapper.get('[data-test="range"]').text()).toBe('19 Sep – 25 Sep');
        expect(strip(wrapper).findAll('button')).toHaveLength(7);
        expect(strip(wrapper).find('[aria-pressed="true"]').text()).toContain('24');

        const rows = wrapper.findAll('[data-test="timeline"] > *');
        expect(rows.map((r) => r.attributes('data-kind'))).toEqual(['meal', 'workout', 'meal']);
        expect(rows[0]!.text()).toContain('780 kcal · P 51 F 21 C 96');
        expect(rows[0]!.text()).toContain('No foods yet');
        expect(rows[1]!.text()).toContain('Lower B · 10:00 AM · 50 min');
        expect(rows[2]!.text()).toContain('Checked in');
        expect(rows[2]!.attributes('disabled')).toBeDefined();
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MEAL-060 copies the foods to the meals picked, with the options, as one meal_copy', async () => {
        const wrapper = show();

        expect(save(wrapper).attributes('disabled')).toBeDefined();
        await wrapper.findAll('[data-kind="meal"]')[0]!.trigger('click');
        expect(wrapper.get('[data-test="chosen"]').text()).toBe('1 meal chosen');
        await save(wrapper).trigger('click');
        await flushPromises();

        expect(sent('meal_copy')).toEqual({
            meal_id: SOURCE_MEAL,
            target_meal_ids: ['01JBX0M1N2P3Q4R5S6T7V8W9X0'],
            update_target_macros: true,
            update_meal_times: false,
            copy_locked_state: false,
        });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('MEAL-060 never picks a meal that cannot take the copy', async () => {
        const wrapper = show();

        await wrapper.findAll('[data-kind="meal"]')[1]!.trigger('click');

        expect(wrapper.find('[data-test="chosen"]').exists()).toBe(false);
        expect(save(wrapper).attributes('disabled')).toBeDefined();
    });

    it('MEAL-060 reads another day of the strip, and another week from the arrows, keeping the meals picked', async () => {
        const wrapper = show();
        await wrapper.findAll('[data-kind="meal"]')[0]!.trigger('click');

        await strip(wrapper).findAll('button')[6]!.trigger('click');
        await flushPromises();
        expect(readMock).toHaveBeenLastCalledWith('copy_show', { meal_id: SOURCE_MEAL, source_date: null, shown_date: '2026-09-25' }, expect.any(Function), expect.any(Function));

        await wrapper.get('[aria-label="Next week"]').trigger('click');
        await flushPromises();
        expect(readMock).toHaveBeenLastCalledWith('copy_show', { meal_id: SOURCE_MEAL, source_date: null, shown_date: '2026-10-01' }, expect.any(Function), expect.any(Function));
        expect(wrapper.get('[data-test="chosen"]').text()).toBe('1 meal chosen');

        await strip(wrapper).findAll('button')[0]!.trigger('click');
        expect(readMock).toHaveBeenCalledTimes(2);
    });

    it('MEAL-060 changes the three options in the gear sheet; Cancel there keeps them as they were', async () => {
        const wrapper = show();

        await wrapper.get('[aria-label="Advanced options"]').trigger('click');
        const options = wrapper.findAll('[role="dialog"]')[1]!;
        expect(options.get('h2').text()).toBe('Advanced options');
        expect(options.findAll('input[role="switch"]').map((i) => (i.element as HTMLInputElement).checked)).toEqual([true, false, false]);
        await options.findAll('input[role="switch"]')[1]!.setValue(true);
        await options.findAll('input[role="switch"]')[2]!.setValue(true);
        await options.get('[data-save]').trigger('click');
        expect(wrapper.findAll('[role="dialog"]')).toHaveLength(1);

        await wrapper.get('[aria-label="Advanced options"]').trigger('click');
        await wrapper.findAll('[role="dialog"]')[1]!.findAll('input[role="switch"]')[0]!.setValue(false);
        await wrapper.findAll('[role="dialog"]')[1]!.findAll('header button')[0]!.trigger('click');

        await wrapper.findAll('[data-kind="meal"]')[0]!.trigger('click');
        await save(wrapper).trigger('click');
        await flushPromises();
        expect(sent('meal_copy')).toMatchObject({ update_target_macros: true, update_meal_times: true, copy_locked_state: true });
    });

    it('SCH-034 copies a day to the days picked on the strip, as one day_copy', async () => {
        const wrapper = show({ payload: day, sourceDate: '2026-09-23' });

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Copy day');
        expect(wrapper.get('[data-test="source"]').text()).toContain('From Wednesday, Sep 23');
        expect(wrapper.text()).toContain('Copy to days');
        expect(wrapper.get('[data-test="day-hint"]').text()).toBe("Tap the days to copy this day's foods to.");
        expect(strip(wrapper).findAll('[aria-pressed="true"]')).toHaveLength(0);

        await strip(wrapper).findAll('button')[5]!.trigger('click');
        await strip(wrapper).findAll('button')[6]!.trigger('click');
        await strip(wrapper).findAll('button')[0]!.trigger('click');
        expect(wrapper.get('[data-test="day-hint"]').text()).toBe('2 days chosen');
        expect(strip(wrapper).findAll('[aria-pressed="true"]')).toHaveLength(2);
        expect(readMock).not.toHaveBeenCalled();

        await save(wrapper).trigger('click');
        await flushPromises();
        expect(sent('day_copy')).toEqual({
            source_date: '2026-09-23',
            target_dates: ['2026-09-24', '2026-09-25'],
            update_target_macros: true,
            update_meal_times: false,
            copy_locked_state: false,
        });
    });

    it('MEAL-060 says when the shown day has no meals', () => {
        const wrapper = show({ payload: { ...foods, timeline: [], meals: [], workouts: [] }, mealId: SOURCE_MEAL });

        expect(wrapper.get('[data-test="no-meals"]').text()).toBe('No meals planned this day.');
    });

    it('OFF-008 dims the strip\'s calories and the meals\' targets while a write waits to sync', () => {
        const wrapper = show({ payload: foods, mealId: SOURCE_MEAL, pending: true });

        expect(strip(wrapper).find('[data-calories]').classes()).toContain('opacity-60');
        const target = wrapper.findAll('[data-kind="meal"]')[0]!.get('[data-test="target"]');
        expect(target.find('[data-value]').classes()).toContain('opacity-60');
        expect(target.text()).toContain('Waiting to sync');
    });

    it('CAP-002 reads copy_show for the meal it copies, showing a skeleton meanwhile', async () => {
        readMock.mockReturnValue(new Promise(() => undefined));
        const wrapper = show({ mealId: SOURCE_MEAL });
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('copy_show', { meal_id: SOURCE_MEAL, source_date: null, shown_date: null }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(save(wrapper).attributes('disabled')).toBeDefined();
    });

    it('CAP-002 says why the copy did not load, with Try again', async () => {
        readMock.mockRejectedValue(new CapabilityError('copy_show', 500, null));
        const wrapper = show({ sourceDate: '2026-09-23' });
        await flushPromises();

        expect(wrapper.get('[role="dialog"] [role="alert"]').text()).toContain('The copy could not be loaded.');
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(true);
    });

    it('CAP-012 keeps the sheet open with the server\'s words when the copy is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_copy', 422, { ok: false, error: { message: 'Lunch is checked in.' } }));
        const wrapper = show();

        await wrapper.findAll('[data-kind="meal"]')[0]!.trigger('click');
        await save(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="dialog"] [role="alert"]').text()).toContain('Lunch is checked in.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('CAP-002 reads copy_show for the day it copies, and keeps that day when it shows another week', async () => {
        readMock.mockReturnValueOnce(new Promise(() => undefined));
        const pendingRead = show({ sourceDate: '2026-09-23' });
        await flushPromises();
        expect(readMock).toHaveBeenLastCalledWith('copy_show', { meal_id: null, source_date: '2026-09-23', shown_date: null }, expect.any(Function), expect.any(Function));
        expect(pendingRead.find('[data-test="skeleton"]').exists()).toBe(true);

        const wrapper = show({ payload: day, sourceDate: '2026-09-23' });
        await wrapper.get('[aria-label="Next week"]').trigger('click');
        await flushPromises();
        expect(readMock).toHaveBeenLastCalledWith('copy_show', { meal_id: null, source_date: '2026-09-23', shown_date: '2026-10-01' }, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 sends each read the day it asked for, even when the user moves on before it is sent', async () => {
        const wrapper = show();

        await strip(wrapper).findAll('button')[6]!.trigger('click');
        await wrapper.get('[aria-label="Previous week"]').trigger('click');
        await flushPromises();

        const asked = readMock.mock.calls.map((call) => (call[1] as { shown_date: string }).shown_date);
        expect(asked).toEqual(['2026-09-25', '2026-09-17']);
    });

    it('UI-022 cannot be dismissed while its write is on the way', async () => {
        writeMock.mockReturnValue(new Promise(() => undefined));
        const wrapper = show();

        await wrapper.findAll('[data-kind="meal"]')[0]!.trigger('click');
        await save(wrapper).trigger('click');
        await wrapper.find('[data-scrim]').trigger('click');

        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.findAll('[role="dialog"]')).toHaveLength(1);
    });

    it('OFF-004 reads the week again when the device reconnects after an offline failure, and not once it is closed', async () => {
        const wrapper = show();
        readMock.mockRejectedValueOnce(new CapabilityError('copy_show', 0, { reason: 'network' })).mockResolvedValueOnce(foods);
        await wrapper.get('[aria-label="Next week"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain("back online");

        window.dispatchEvent(new Event('online'));
        await flushPromises();
        expect(readMock).toHaveBeenLastCalledWith('copy_show', { meal_id: SOURCE_MEAL, source_date: null, shown_date: '2026-10-01' }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="source"]').exists()).toBe(true);

        readMock.mockRejectedValueOnce(new CapabilityError('copy_show', 0, { reason: 'network' }));
        await wrapper.get('[aria-label="Next week"]').trigger('click');
        await flushPromises();
        wrapper.unmount();
        window.dispatchEvent(new Event('online'));
        await flushPromises();
        expect(readMock).toHaveBeenCalledTimes(3);
    });
});

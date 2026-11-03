import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { read } from '@/app/call';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { screenFixture } from '@/testing/schemas';
import LookBackSheet from './LookBackSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));
vi.mock('@/app/outbox', async (original) => ({ ...(await original<typeof import('@/app/outbox')>()), isPending: vi.fn(() => false) }));

const readMock = vi.mocked(read);
const payload = () => screenFixture<WorkoutShowOutput>('workout_show');

function show(data = payload(), session: WorkoutShowOutput | undefined = payload()) {
    return mount(LookBackSheet, { props: { payload: data, session }, global: { stubs: { teleport: true } } });
}

describe('LookBackSheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
    });

    it('WO-008 lists the earlier sessions with their status', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Look back');
        const rows = wrapper.findAll('[data-test="session"]');
        expect(rows).toHaveLength(4);
        expect(rows[0]!.text()).toContain('Week 2 · Day 2 · Lower A');
        expect(rows[0]!.get('[data-test="status"]').text()).toBe('Done');
        expect(rows[2]!.get('[data-test="status"]').text()).toBe('Skipped');
        expect(rows[0]!.get('[data-test="status"]').classes().join(' ')).toContain('tone-good');
    });

    it('WO-008 says when there are no earlier sessions (workout_show.empty)', () => {
        const wrapper = show(screenFixture<WorkoutShowOutput>('workout_show.empty'));

        expect(wrapper.get('[data-test="none"]').text()).toBe('No earlier sessions in this mesocycle yet.');
        expect(wrapper.find('[data-test="session"]').exists()).toBe(false);
    });

    it('WO-008 a tap shows that session in the sheet, and Back returns to the list', async () => {
        const wrapper = show();

        await wrapper.findAll('[data-test="session"]')[1]!.trigger('click');

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Week 2 · Day 1 · Upper A');
        expect(wrapper.find('[data-test="session"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="look-back-detail"]').exists()).toBe(true);
        expect(wrapper.get('[data-cancel]').text()).toBe('Back');
        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Look back');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('WO-008 Done closes the sheet from the list', async () => {
        const wrapper = show();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.get('[data-cancel]').text()).toBe('Done');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

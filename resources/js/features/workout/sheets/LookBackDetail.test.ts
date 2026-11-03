import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import { isPending } from '@/app/outbox';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { screenFixture } from '@/testing/schemas';
import LookBackDetail from './LookBackDetail.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));
vi.mock('@/app/outbox', async (original) => ({ ...(await original<typeof import('@/app/outbox')>()), isPending: vi.fn(() => false) }));

const readMock = vi.mocked(read);
const session = () => {
    const data = screenFixture<WorkoutShowOutput>('workout_show');
    data.exercises[0]!.sets[0]!.is_logged = true;
    data.exercises[0]!.sets[1]!.is_logged = true;
    data.exercises[0]!.sets[2]!.is_logged = false;
    data.exercises[1]!.sets.forEach((set) => (set.is_logged = false));
    return data;
};

function show(props: Record<string, unknown> = { payload: session() }) {
    return mount(LookBackDetail, { props: { mesocycleId: 'm1', week: 2, day: 1, ...props } });
}

describe('LookBackDetail', () => {
    beforeEach(() => {
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
        vi.mocked(isPending).mockReturnValue(false);
    });

    it('WO-008 lists each exercise with its logged sets as weight × reps, and says when none was logged', () => {
        const wrapper = show();

        const exercises = wrapper.findAll('[data-test="exercise"]');
        expect(exercises.map((exercise) => exercise.get('h3').text())).toEqual(['Bench Press', 'Barbell Row']);
        const lines = exercises[0]!.findAll('[data-test="set"]');
        expect(lines).toHaveLength(2);
        expect(lines[0]!.text()).toBe('Set 1 82.5 kg × 10');
        expect(exercises[1]!.get('[data-test="nothing"]').text()).toBe('Nothing logged');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('WO-008 shows nothing for a session with no exercises (workout_show.empty)', () => {
        const wrapper = show({ payload: screenFixture<WorkoutShowOutput>('workout_show.empty') });

        expect(wrapper.find('[data-test="exercise"]').exists()).toBe(false);
    });

    it('CAP-002 reads the session it names, with a skeleton until it comes', () => {
        const wrapper = show({});

        expect(readMock).toHaveBeenCalledWith('workout_show', { mesocycle_id: 'm1', week: 2, day: 1 }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says when the session could not load, and Try again reads again', async () => {
        readMock.mockRejectedValue(new CapabilityError('workout_show', 500, null));
        const wrapper = show({});
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('That session could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
    });

    it('OFF-008 while a change waits to sync, the numbers are marked pending', () => {
        vi.mocked(isPending).mockReturnValue(true);
        const wrapper = show();

        expect(isPending).toHaveBeenCalledWith('workout_show');
        expect(wrapper.get('[data-test="set"] [data-value]').classes()).toContain('opacity-60');
        expect(wrapper.get('[data-test="set"]').text()).toContain('Waiting to sync');
    });

    it('OFF-008 shows no pending mark when nothing waits', () => {
        expect(show().find('[data-mark]').exists()).toBe(false);
    });
});

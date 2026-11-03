import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { isPending } from '@/app/outbox';
import { showWorkout } from '@/features/workout/activeWorkout';
import ActiveWorkoutBar from './ActiveWorkoutBar.vue';

vi.mock('@/app/outbox', async (original) => ({ ...(await original<typeof import('@/app/outbox')>()), isPending: vi.fn(() => false) }));

const PATH = '/workouts/m1/3/1';
let router: Router;

async function show(at = '/today') {
    router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push(at);
    const wrapper = mount(ActiveWorkoutBar, { global: { plugins: [router] } });
    await flushPromises();
    return wrapper;
}

describe('ActiveWorkoutBar', () => {
    beforeEach(() => {
        document.cookie = 'bb_cache_owner=a1; path=/';
        localStorage.clear();
        showWorkout('w1', PATH, null);
        vi.mocked(isPending).mockReturnValue(false);
    });

    it('NAV-004 shows the workout under way above the tabs, and a tap opens it', async () => {
        showWorkout('w1', PATH, 'Upper A · 4 of 6 exercises');
        const wrapper = await show();

        const bar = wrapper.get('[data-test="active-workout"]');
        expect(bar.text()).toContain('Upper A · 4 of 6 exercises');
        expect(bar.attributes('aria-label')).toBe('Workout in progress, Upper A · 4 of 6 exercises');
        await bar.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe(PATH);
        expect(wrapper.find('[data-test="active-workout"]').exists()).toBe(false);
    });

    it('NAV-004 shows nothing with no workout under way, or once it is finished', async () => {
        expect((await show()).find('[data-test="active-workout"]').exists()).toBe(false);

        showWorkout('w1', PATH, 'Upper A · 4 of 6 exercises');
        showWorkout('w1', PATH, null);
        expect((await show()).find('[data-test="active-workout"]').exists()).toBe(false);
    });

    it('NAV-004 another workout without a bar does not take down the one under way', async () => {
        showWorkout('w1', PATH, 'Upper A · 4 of 6 exercises');
        showWorkout('w2', '/workouts/m1/2/1', null);

        expect((await show()).find('[data-test="active-workout"]').exists()).toBe(true);
    });

    it('NAV-004 another user on the device never sees it (OFF-012)', async () => {
        showWorkout('w1', PATH, 'Upper A · 4 of 6 exercises');
        document.cookie = 'bb_cache_owner=b2; path=/';

        expect((await show()).find('[data-test="active-workout"]').exists()).toBe(false);
    });

    it('NAV-004 survives a reload, from the device', async () => {
        showWorkout('w1', PATH, 'Upper A · 4 of 6 exercises');

        expect(JSON.parse(localStorage.getItem('bb.workout.active')!)).toMatchObject({ user: 'a1', path: PATH });
    });

    it('OFF-008 dims the server\'s count while a change waits to sync', async () => {
        showWorkout('w1', PATH, 'Upper A · 4 of 6 exercises');
        vi.mocked(isPending).mockReturnValue(true);
        const wrapper = await show();

        expect(wrapper.get('[data-test="active-workout"] [data-value]').classes()).toContain('opacity-60');
        expect(isPending).toHaveBeenCalledWith('workout_show');
    });
});

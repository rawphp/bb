import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { read, write } from '@/app/call';
import IntroView from './IntroView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

async function intro() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/welcome');
    const wrapper = mount(IntroView, { global: { plugins: [router] }, attachTo: document.body });
    return { wrapper, router };
}

const titles = ['Meals planned first', 'Training that adjusts to you', 'One review each week'];

describe('IntroView', () => {
    it('ONB-001 explains plan-first meals, training that adjusts, and one weekly review', async () => {
        const { wrapper } = await intro();

        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(titles);
        expect(wrapper.text()).toContain('Pick your foods and the app fits them to your targets.');
        expect(wrapper.text()).toContain('Your sets and loads change to match your feedback.');
        expect(wrapper.text()).toContain('One decision a week.');
        expect(read).not.toHaveBeenCalled();
        expect(write).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('ONB-001 Next walks the cards, and the page dots say which one shows', async () => {
        const { wrapper } = await intro();
        const current = () => wrapper.findAll('[data-test="dot"]').map((d) => d.attributes('aria-current'));
        const shown = () => wrapper.findAll('[data-test="card"]').map((c) => c.attributes('aria-hidden'));

        expect(current()).toEqual(['true', undefined, undefined]);
        expect(shown()).toEqual([undefined, 'true', 'true']);
        await wrapper.get('[data-test="intro-next"]').trigger('click');

        expect(current()).toEqual([undefined, 'true', undefined]);
        expect(shown()).toEqual(['true', undefined, 'true']);
        expect(wrapper.get('[data-test="intro-next"]').text()).toBe('Next');

        await wrapper.findAll('[data-test="dot"]')[2]!.trigger('click');
        expect(wrapper.get('[data-test="intro-next"]').text()).toBe('Get started');
        wrapper.unmount();
    });

    it('ONB-001 the last card leads to sign-up', async () => {
        const { wrapper, router } = await intro();

        await wrapper.get('[data-test="intro-next"]').trigger('click');
        await wrapper.get('[data-test="intro-next"]').trigger('click');
        await wrapper.get('[data-test="intro-next"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/sign-in');
        wrapper.unmount();
    });
});

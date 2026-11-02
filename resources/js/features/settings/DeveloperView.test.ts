import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import devRoutes from '@/features/dev/routes';
import DeveloperSection from './DeveloperSection.vue';
import DeveloperView from './DeveloperView.vue';

describe('DeveloperView', () => {
    it('SET-011 shows the time-walk clock on its own page', () => {
        const wrapper = mount(DeveloperView);

        expect(wrapper.get('h1').text()).toBe('Developer');
        expect(wrapper.findComponent(DeveloperSection).exists()).toBe(true);
    });

    it('SET-011 is a local and testing route only', () => {
        expect(devRoutes.find((route) => route.path === '/dev/clock')?.meta.dev).toBe(true);
    });
});

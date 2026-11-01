import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { clearWalk, isWalked, now } from '@/app/clock';
import DeveloperSection from './DeveloperSection.vue';

const DAY = 86_400_000;
const start = new Date(2026, 10, 2, 9, 30);

describe('DeveloperSection', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(start);
        clearWalk();
    });

    afterEach(() => {
        clearWalk();
        vi.useRealTimers();
    });

    it('SET-011 shows the device date and no Clear while the clock is not walked', () => {
        const wrapper = mount(DeveloperSection);

        expect(wrapper.get<HTMLInputElement>('input[type="date"]').element.value).toBe('2026-11-02');
        expect(wrapper.text()).toContain('Device time');
        expect(wrapper.find('[data-test="clear"]').exists()).toBe(false);
    });

    it('SET-011 walks the clock with +1 day and +7 days', async () => {
        const wrapper = mount(DeveloperSection);

        await wrapper.get('[data-test="plus-day"]').trigger('click');
        await wrapper.get('[data-test="plus-week"]').trigger('click');

        expect(now().getTime()).toBe(start.getTime() + 8 * DAY);
        expect(wrapper.get<HTMLInputElement>('input[type="date"]').element.value).toBe('2026-11-10');
        expect(wrapper.text()).toContain('Walked');
    });

    it('SET-011 walks to the picked date', async () => {
        const wrapper = mount(DeveloperSection);

        await wrapper.get('input[type="date"]').setValue('2027-01-15');

        expect(now()).toEqual(new Date(2027, 0, 15, 9, 30));
    });

    it('SET-011 clears the walk', async () => {
        const wrapper = mount(DeveloperSection);
        await wrapper.get('[data-test="plus-week"]').trigger('click');

        await wrapper.get('[data-test="clear"]').trigger('click');

        expect(isWalked()).toBe(false);
        expect(wrapper.get<HTMLInputElement>('input[type="date"]').element.value).toBe('2026-11-02');
    });
});

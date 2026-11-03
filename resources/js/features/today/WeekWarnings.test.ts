import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { WeekShowOutput } from '@/contracts/week_show';
import { screenFixture } from '@/testing/schemas';
import WeekWarnings from './WeekWarnings.vue';

const week = (state?: string) => screenFixture<WeekShowOutput>(state ? `week_show.${state}` : 'week_show');

describe('WeekWarnings', () => {
    it("SCH-062 shows the week's too much cardio warning as a notice", () => {
        const wrapper = mount(WeekWarnings, { props: { warnings: week('warning').warnings } });

        const notice = wrapper.get('[role="status"]');
        expect(notice.text()).toBe('Too much cardio hurts lifting recovery. That makes 5 hard sessions this week; more than 4 is a lot during fat loss.');
        expect(notice.classes()).toContain('text-tone-warning');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('SCH-062 shows nothing for a week without warnings', () => {
        const wrapper = mount(WeekWarnings, { props: { warnings: week().warnings } });

        expect(week().warnings).toEqual([]);
        expect(wrapper.find('[data-test="week-warnings"]').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
    });
});

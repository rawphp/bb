import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MacroBar from './MacroBar.vue';

const bar = (label: string) => ({ label, value_text: '45/40', fraction: 0.5, tone: 'neutral' as const });

describe('MacroBar', () => {
    it.each([
        ['Cal', 'Calories', 'bg-macro-calories'],
        ['P', 'Protein', 'bg-macro-protein'],
        ['F', 'Fat', 'bg-macro-fat'],
        ['C', 'Carbs', 'bg-macro-carbs'],
        ['Fibre', 'Fibre', 'bg-accent'],
    ])('UI-011 draws %s in its own colour, named %s for a screen reader', (label, title, fill) => {
        const wrapper = mount(MacroBar, { props: { bar: bar(label) } });

        expect(wrapper.get('[role="progressbar"]').attributes('aria-label')).toBe(title);
        expect(wrapper.find(`.${fill}`).exists()).toBe(true);
    });
});

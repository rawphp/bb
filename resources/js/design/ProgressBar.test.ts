import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import ProgressBar from './ProgressBar.vue';
import ProgressBarGallery from './gallery/ProgressBar.gallery.vue';

const props = { title: 'Protein', label: 'P', valueText: '45/40', fraction: 0.5, tint: 'protein' } as const;

function bar(extra: Record<string, unknown> = {}) {
    return mount(ProgressBar, { props: { ...props, ...extra } });
}

function fill(wrapper: ReturnType<typeof bar>) {
    return wrapper.get('[role="progressbar"] span.absolute');
}

describe('ProgressBar', () => {
    it('UI-003 draws no label slot when the bar has no short label', () => {
        const wrapper = mount(ProgressBar, { props: { title: 'Phase progress', valueText: 'Week 7 of 12', fraction: 0.5 } });

        expect(wrapper.find('span.font-semibold').exists()).toBe(false);
        expect(wrapper.text()).toBe('Week 7 of 12');
    });

    it('UI-003 shows the short label, a bar filled to the fraction and the value/target text', () => {
        const wrapper = bar();

        expect(wrapper.text()).toBe('P45/40');
        expect(fill(wrapper).attributes('style')).toContain('width: 50%');
        expect(fill(wrapper).classes()).toContain('bg-macro-protein');
        expect(wrapper.get('span.font-semibold').classes()).toContain('text-macro-protein');
        expect(wrapper.emitted()).toEqual({});
    });

    it('UI-003 macro and set bars share the bar; set bars use the accent', () => {
        const wrapper = bar({ title: 'Sets', label: 'Sets', valueText: '3/4', fraction: 0.75, tint: undefined });

        expect(fill(wrapper).classes()).toContain('bg-accent');
        expect(wrapper.text()).toBe('Sets3/4');
    });

    it('UI-003 the fill stays inside the track', () => {
        expect(fill(bar({ fraction: 1.4 })).attributes('style')).toContain('width: 100%');
        expect(fill(bar({ fraction: -0.2 })).attributes('style')).toContain('width: 0%');
    });

    it('UI-030 the bar is one labelled element read as "45 of 40"', () => {
        const root = bar().get('[role="progressbar"]');

        expect(root.attributes('aria-label')).toBe('Protein');
        expect(root.attributes('aria-valuetext')).toBe('45 of 40');
        for (const child of root.element.children) {
            expect(child.getAttribute('aria-hidden')).toBe('true');
        }
    });

    it('UI-030 a warning tone shows a warning icon and says over target, not by colour alone', () => {
        const wrapper = bar({ tone: 'warning' });

        expect(wrapper.find('svg').exists()).toBe(true);
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuetext')).toBe('45 of 40, over target');
    });

    it('CAP-018 only the warning tone means over target', () => {
        for (const tone of [undefined, 'good', 'neutral', 'info']) {
            const wrapper = bar({ tone });

            expect(wrapper.find('svg').exists()).toBe(false);
            expect(wrapper.get('[role="progressbar"]').attributes('aria-valuetext')).toBe('45 of 40');
        }
    });

    it('VIEW-004 empty: an empty track with the zero value', () => {
        const wrapper = bar({ valueText: '0/2600', fraction: 0 });

        expect(fill(wrapper).attributes('style')).toContain('width: 0%');
        expect(wrapper.text()).toContain('0/2600');
    });

    it('VIEW-004 loading: a pulsing empty track announced as loading', () => {
        const wrapper = bar({ loading: true, tone: 'warning', pending: true });
        const root = wrapper.get('[role="progressbar"]');

        expect(root.attributes('aria-busy')).toBe('true');
        expect(root.attributes('aria-valuetext')).toBe('Loading');
        expect(wrapper.find('.animate-pulse').exists()).toBe(true);
        expect(fill(wrapper).attributes('style')).toContain('width: 0%');
        expect(wrapper.text()).toBe('PLoading');
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it("VIEW-004 error: the value is replaced by the error's words in the warning tone", () => {
        const wrapper = bar({ error: 'No connection', tone: 'warning', pending: true });
        const root = wrapper.get('[role="progressbar"]');

        expect(wrapper.text()).toBe('PNo connection');
        expect(root.attributes('aria-valuetext')).toBe('No connection');
        expect(root.attributes('aria-busy')).toBeUndefined();
        expect(wrapper.get('.text-tone-warning').text()).toBe('No connection');
        expect(fill(wrapper).attributes('style')).toContain('width: 0%');
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it('VIEW-004 loading wins over an error, as in HelpButton', () => {
        const wrapper = bar({ loading: true, error: 'No connection' });

        expect(wrapper.text()).toBe('PLoading');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuetext')).toBe('Loading');
    });

    it('VIEW-004 pending: the value is dimmed and Waiting to sync stays readable (OFF-008)', () => {
        const wrapper = bar({ pending: true, tone: 'warning' });
        const root = wrapper.get('[role="progressbar"]');
        const note = wrapper.findAll('span').find((s) => s.text() === 'Waiting to sync');

        expect(root.classes()).not.toContain('opacity-50');
        expect(note?.classes()).not.toContain('opacity-50');
        for (const part of [...root.element.children].filter((c) => c !== note?.element)) {
            expect(part.classList.contains('opacity-50')).toBe(true);
        }
        expect(root.attributes('aria-valuetext')).toBe('45 of 40, over target, waiting to sync');
    });

    it('VIEW-004 disabled: the bar is greyed out and marked disabled', () => {
        const wrapper = bar({ disabled: true });
        const root = wrapper.get('[role="progressbar"]');

        expect(root.attributes('aria-disabled')).toBe('true');
        expect(root.classes()).toContain('opacity-50');
        expect(fill(wrapper).classes()).toContain('bg-secondary-text');
        expect(bar().get('[role="progressbar"]').attributes('aria-disabled')).toBeUndefined();
    });

    it('VIEW-004 the gallery lists ProgressBar with each state', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());
        expect(titles).toContain('ProgressBar');

        const gallery = mount(ProgressBarGallery);
        expect(gallery.findAll('h3').map((h) => h.text())).toEqual([
            'default',
            'over target',
            'empty',
            'loading',
            'error',
            'pending',
            'disabled',
        ]);
        expect(gallery.findAllComponents(ProgressBar)).toHaveLength(7);
    });
});

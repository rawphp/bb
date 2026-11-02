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

    it('UI-030 over target shows a warning icon and says so, not by colour alone', () => {
        const wrapper = bar({ overTarget: true });

        expect(wrapper.find('svg').exists()).toBe(true);
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuetext')).toBe('45 of 40, over target');
        expect(bar().find('svg').exists()).toBe(false);
    });

    it('VIEW-004 empty: an empty track with the zero value', () => {
        const wrapper = bar({ valueText: '0/2600', fraction: 0 });

        expect(fill(wrapper).attributes('style')).toContain('width: 0%');
        expect(wrapper.text()).toContain('0/2600');
    });

    it('VIEW-004 loading: a pulsing empty track announced as loading', () => {
        const wrapper = bar({ state: 'loading', overTarget: true });
        const root = wrapper.get('[role="progressbar"]');

        expect(root.attributes('aria-busy')).toBe('true');
        expect(root.attributes('aria-valuetext')).toBe('Loading');
        expect(wrapper.find('.animate-pulse').exists()).toBe(true);
        expect(fill(wrapper).attributes('style')).toContain('width: 0%');
        expect(wrapper.text()).not.toContain('45/40');
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it('VIEW-004 error: the value is replaced by an error in words', () => {
        const wrapper = bar({ state: 'error' });

        expect(wrapper.text()).toBe("PCouldn't load");
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuetext')).toBe("Couldn't load");
        expect(fill(wrapper).attributes('style')).toContain('width: 0%');
    });

    it('VIEW-004 pending: the bar is dimmed and says Waiting to sync (OFF-008)', () => {
        const root = bar({ state: 'pending' }).get('[role="progressbar"]');

        expect(root.classes()).toContain('opacity-50');
        expect(root.text()).toContain('Waiting to sync');
        expect(root.attributes('aria-valuetext')).toBe('45 of 40, waiting to sync');
    });

    it('VIEW-004 disabled: the bar is greyed out and marked disabled', () => {
        const wrapper = bar({ disabled: true });
        const root = wrapper.get('[role="progressbar"]');

        expect(root.attributes('aria-disabled')).toBe('true');
        expect(root.classes()).toContain('opacity-50');
        expect(fill(wrapper).classes()).toContain('bg-secondary-text');
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

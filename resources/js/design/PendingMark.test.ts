import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import PendingMarkGallery from './gallery/PendingMark.gallery.vue';
import PendingMark from './PendingMark.vue';

// What a screen reader reads: every text node outside aria-hidden.
function spoken(element: Element): string {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const parts: string[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (!node.parentElement?.closest('[aria-hidden="true"]')) parts.push(node.textContent ?? '');
    }
    return parts.join(' ').replace(/\s+/g, ' ').trim();
}

describe('PendingMark', () => {
    it('OFF-009 shows the value as typed with a small "Waiting to sync" mark beside it, and emits nothing', () => {
        const wrapper = mount(PendingMark, { slots: { default: '100 kg × 8' } });

        expect(wrapper.get('[data-value]').text()).toBe('100 kg × 8');
        expect(wrapper.get('[data-value]').classes()).not.toContain('opacity-60');
        expect(wrapper.get('[data-mark]').text()).toBe('Waiting to sync');
        expect(wrapper.emitted()).toEqual({});
    });

    it('OFF-008 a server-derived value is dimmed, with the mark in place of its status text', () => {
        const wrapper = mount(PendingMark, { props: { derived: true }, slots: { default: '1,840 kcal left' } });

        expect(wrapper.get('[data-value]').classes()).toContain('opacity-60');
        expect(wrapper.get('[data-mark]').classes()).not.toContain('opacity-60');
    });

    it('VIEW-004 empty: with no value only the mark shows', () => {
        const wrapper = mount(PendingMark);

        expect(wrapper.find('[data-value]').exists()).toBe(false);
        expect(wrapper.text()).toBe('Waiting to sync');
    });

    it('UI-030 the icon is hidden from screen readers and the mark is read as text, so colour is not the only signal', () => {
        const wrapper = mount(PendingMark, { slots: { default: '82.4 kg' } });

        expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true');
        expect(spoken(wrapper.element)).toBe('82.4 kg Waiting to sync');
    });

    it('OFF-009 an id passed in lands on the mark, so a row can be described by it', () => {
        const wrapper = mount(PendingMark, { attrs: { id: 'set-3-pending' }, slots: { default: '100 kg × 8' } });

        expect(wrapper.get('#set-3-pending').text()).toBe('Waiting to sync');
    });

    it('VIEW-004 the gallery page lists PendingMark and its gallery shows each state', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());
        const states = mount(PendingMarkGallery)
            .findAll('h3')
            .map((h) => h.text());

        expect(titles).toContain('PendingMark');
        expect(states).toEqual(expect.arrayContaining(['Empty', 'Loading', 'Error', 'Pending', 'Disabled']));
    });

    it('UI-011 the gallery is painted with token utilities only', () => {
        expect(mount(PendingMarkGallery).html()).not.toMatch(/#[0-9a-f]{3,6}\b|rgb\(|var\(--/i);
    });
});

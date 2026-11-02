import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import OfflineNoticeGallery from './gallery/OfflineNotice.gallery.vue';
import PendingMarkGallery from './gallery/PendingMark.gallery.vue';
import SkeletonGallery from './gallery/Skeleton.gallery.vue';
import OfflineNotice from './OfflineNotice.vue';
import PendingMark from './PendingMark.vue';
import Skeleton from './Skeleton.vue';

// What a screen reader reads: every text node outside aria-hidden.
function spoken(element: Element): string {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const parts: string[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (!node.parentElement?.closest('[aria-hidden="true"]')) parts.push(node.textContent ?? '');
    }
    return parts.join(' ').replace(/\s+/g, ' ').trim();
}

const STATES = ['Empty', 'Loading', 'Error', 'Pending', 'Disabled'];

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
});

describe('OfflineNotice', () => {
    it('OFF-003 says "Not available offline" and what the screen needs, as a status, and emits nothing', () => {
        const wrapper = mount(OfflineNotice, { props: { needs: 'Food search needs a connection.' } });
        const status = wrapper.get('[role="status"]');

        expect(status.get('[data-title]').text()).toBe('Not available offline');
        expect(status.get('[data-needs]').text()).toBe('Food search needs a connection.');
        expect(wrapper.emitted()).toEqual({});
    });

    it('UI-030 the icon is hidden and the whole notice is read as text', () => {
        const wrapper = mount(OfflineNotice, { props: { needs: 'The Coach needs a connection.' } });

        expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true');
        expect(spoken(wrapper.element)).toBe('Not available offline The Coach needs a connection.');
    });

    it('VIEW-004 empty: with no needs line only the title shows', () => {
        const wrapper = mount(OfflineNotice);

        expect(wrapper.find('[data-needs]').exists()).toBe(false);
        expect(spoken(wrapper.element)).toBe('Not available offline');
    });
});

describe('Skeleton', () => {
    it.each([
        ['row', 'min-h-tap'],
        ['card', 'h-32'],
        ['bar', 'h-2'],
    ] as const)('PWA-024 the %s shape draws a placeholder in that shape', (shape, size) => {
        const wrapper = mount(Skeleton, { props: { shape } });
        const blocks = wrapper.findAll('[data-placeholder]');

        expect(blocks).toHaveLength(1);
        expect(blocks[0]!.classes()).toEqual(expect.arrayContaining([size, 'animate-pulse', 'motion-reduce:animate-none']));
        expect(wrapper.emitted()).toEqual({});
    });

    it('PWA-024 count repeats the shape, for a list of rows', () => {
        expect(mount(Skeleton, { props: { shape: 'row', count: 4 } }).findAll('[data-placeholder]')).toHaveLength(4);
    });

    it('UI-030 the placeholders are hidden and one "Loading" status is read, or the label given', () => {
        const wrapper = mount(Skeleton, { props: { shape: 'row', count: 3 } });

        for (const block of wrapper.findAll('[data-placeholder]')) {
            expect(block.attributes('aria-hidden')).toBe('true');
        }
        expect(wrapper.get('[role="status"]').element).toBe(wrapper.element);
        expect(spoken(wrapper.element)).toBe('Loading');
        expect(spoken(mount(Skeleton, { props: { shape: 'card', label: 'Loading meals' } }).element)).toBe('Loading meals');
    });

    it('VIEW-004 empty: a count of zero draws no placeholders and says nothing', () => {
        const wrapper = mount(Skeleton, { props: { shape: 'row', count: 0 } });

        expect(wrapper.findAll('[data-placeholder]')).toHaveLength(0);
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });
});

describe('state component galleries', () => {
    it('VIEW-004 the gallery page lists all three components', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());

        expect(titles).toEqual(expect.arrayContaining(['OfflineNotice', 'PendingMark', 'Skeleton']));
    });

    it.each([
        ['PendingMark', PendingMarkGallery],
        ['OfflineNotice', OfflineNoticeGallery],
        ['Skeleton', SkeletonGallery],
    ])('VIEW-004 the %s gallery shows each state', (_, gallery) => {
        const states = mount(gallery)
            .findAll('h3')
            .map((h) => h.text());

        expect(states).toEqual(expect.arrayContaining(STATES));
    });

    it('UI-011 the components are painted with token utilities only', () => {
        for (const gallery of [PendingMarkGallery, OfflineNoticeGallery, SkeletonGallery]) {
            expect(mount(gallery).html()).not.toMatch(/#[0-9a-f]{3,6}\b|rgb\(|var\(--/i);
        }
    });
});

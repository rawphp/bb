import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import SkeletonGallery from './gallery/Skeleton.gallery.vue';
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

    it('VIEW-004 the gallery page lists Skeleton and its gallery shows each state', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());
        const states = mount(SkeletonGallery)
            .findAll('h3')
            .map((h) => h.text());

        expect(titles).toContain('Skeleton');
        expect(states).toEqual(expect.arrayContaining(['Empty', 'Loading', 'Error', 'Pending', 'Disabled']));
    });

    it('UI-011 the gallery is painted with token utilities only', () => {
        expect(mount(SkeletonGallery).html()).not.toMatch(/#[0-9a-f]{3,6}\b|rgb\(|var\(--/i);
    });
});

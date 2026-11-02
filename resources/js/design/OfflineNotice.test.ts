import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import OfflineNoticeGallery from './gallery/OfflineNotice.gallery.vue';
import OfflineNotice from './OfflineNotice.vue';

// What a screen reader reads: every text node outside aria-hidden.
function spoken(element: Element): string {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const parts: string[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (!node.parentElement?.closest('[aria-hidden="true"]')) parts.push(node.textContent ?? '');
    }
    return parts.join(' ').replace(/\s+/g, ' ').trim();
}

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

    it('VIEW-004 the gallery page lists OfflineNotice and its gallery shows each state', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());
        const states = mount(OfflineNoticeGallery)
            .findAll('h3')
            .map((h) => h.text());

        expect(titles).toContain('OfflineNotice');
        expect(states).toEqual(expect.arrayContaining(['Empty', 'Loading', 'Error', 'Pending', 'Disabled']));
    });

    it('UI-011 the gallery is painted with token utilities only', () => {
        expect(mount(OfflineNoticeGallery).html()).not.toMatch(/#[0-9a-f]{3,6}\b|rgb\(|var\(--/i);
    });
});

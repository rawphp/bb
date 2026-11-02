import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import StatusPill from './StatusPill.vue';
import StatusPillGallery from './gallery/StatusPill.gallery.vue';

type Props = InstanceType<typeof StatusPill>['$props'];

function pill(extra: Partial<Props> = {}) {
    return mount(StatusPill, { props: { text: 'on track', tone: 'good', ...extra } });
}

// What a screen reader reads: every text node outside aria-hidden.
function spoken(wrapper: ReturnType<typeof pill>): string {
    const walker = document.createTreeWalker(wrapper.element, NodeFilter.SHOW_TEXT);
    const parts: string[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (!node.parentElement?.closest('[aria-hidden="true"]')) parts.push(node.textContent ?? '');
    }
    return parts.join(' ').replace(/\s+/g, ' ').replace(' ,', ',').trim();
}

describe('StatusPill', () => {
    it('UI-004 shows the server text in a capsule with no emitted events', () => {
        const wrapper = pill();

        expect(wrapper.text()).toBe('on track');
        expect(wrapper.classes()).toContain('rounded-full');
        expect(wrapper.emitted()).toEqual({});
    });

    it('UI-004 each of the four tones paints its token pair and its own icon', () => {
        const paths = new Set<string>();
        for (const tone of ['good', 'neutral', 'warning', 'info'] as const) {
            const wrapper = pill({ tone });

            expect(wrapper.classes()).toEqual(expect.arrayContaining([`text-tone-${tone}`, `bg-tone-${tone}-bg`]));
            paths.add(wrapper.get('svg path').attributes('d')!);
        }
        expect(paths.size).toBe(4);
    });

    it('UI-030 the icon is hidden from screen readers and the text is read', () => {
        const wrapper = pill({ text: 'Adjust foods', tone: 'warning' });

        expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true');
        expect(spoken(wrapper)).toBe('Adjust foods');
    });

    it('UI-030 the pill is styled only through token utilities', () => {
        expect(pill().html()).not.toMatch(/#[0-9a-f]{3,6}\b|var\(--/i);
    });

    it('VIEW-004 empty: no text renders no pill, so colour is never shown alone', () => {
        expect(pill({ text: '' }).html()).toBe('<!--v-if-->');
    });

    it('VIEW-004 loading: a pulsing neutral pill that says Loading', () => {
        const wrapper = pill({ state: 'loading' });

        expect(wrapper.classes()).toEqual(expect.arrayContaining(['animate-pulse', 'bg-tone-neutral-bg']));
        expect(wrapper.find('svg').exists()).toBe(false);
        expect(spoken(wrapper)).toBe('Loading');
    });

    it('VIEW-004 error: a neutral pill with a warning icon and the error in words', () => {
        const wrapper = pill({ state: 'error' });

        expect(wrapper.classes()).toContain('bg-tone-neutral-bg');
        expect(wrapper.get('svg path').attributes('d')).toBe(pill({ tone: 'warning' }).get('svg path').attributes('d'));
        expect(spoken(wrapper)).toBe("Couldn't load");
    });

    it('VIEW-004 pending: keeps its tone and says Waiting to sync (OFF-008)', () => {
        const wrapper = pill({ state: 'pending' });

        expect(wrapper.classes()).toContain('bg-tone-good-bg');
        expect(spoken(wrapper)).toBe('on track · Waiting to sync');
    });

    it('VIEW-004 disabled: greyed out and read as unavailable', () => {
        const wrapper = pill({ disabled: true });

        expect(wrapper.classes()).toEqual(expect.arrayContaining(['opacity-50', 'bg-tone-neutral-bg']));
        expect(spoken(wrapper)).toBe('on track, unavailable');
    });

    it('VIEW-004 the gallery lists StatusPill with each tone and state', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());
        expect(titles).toContain('StatusPill');

        const gallery = mount(StatusPillGallery);
        expect(gallery.findAll('h3').map((h) => h.text())).toEqual([
            'good',
            'neutral',
            'warning',
            'info',
            'empty',
            'loading',
            'error',
            'pending',
            'disabled',
        ]);
        expect(gallery.findAllComponents(StatusPill)).toHaveLength(9);
    });
});

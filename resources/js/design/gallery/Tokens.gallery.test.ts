import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import TokensGallery from './Tokens.gallery.vue';

describe('Tokens gallery', () => {
    afterEach(() => {
        localStorage.clear();
        delete document.documentElement.dataset.appearance;
    });

    it('PWA-040 the gallery page lists the tokens entry', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());

        expect(titles).toContain('Tokens');
    });

    it('PWA-041 the appearance switch repaints the page without a reload', async () => {
        const wrapper = mount(TokensGallery);

        await wrapper.get('[role="radio"]:nth-child(3)').trigger('click');
        expect(document.documentElement.dataset.appearance).toBe('dark');
        expect(wrapper.get('[aria-checked="true"]').text()).toBe('dark');

        await wrapper.get('[role="radio"]:nth-child(1)').trigger('click');
        expect(document.documentElement.dataset.appearance).toBeUndefined();
    });

    it('PWA-040 the gallery paints every token through its Tailwind utility', () => {
        const wrapper = mount(TokensGallery);

        expect(wrapper.html()).not.toContain('var(--bb-');
        for (const tone of ['good', 'neutral', 'warning', 'info']) {
            expect(wrapper.find(`.text-tone-${tone}.bg-tone-${tone}-bg`).exists()).toBe(true);
        }
        for (const macro of ['calories', 'protein', 'fat', 'carbs']) {
            expect(wrapper.findAll(`.text-macro-${macro}`)).toHaveLength(2);
        }
        expect(wrapper.find('.bg-background .text-primary-text').exists()).toBe(true);
        expect(wrapper.find('.bg-surface .text-secondary-text').exists()).toBe(true);
    });
});

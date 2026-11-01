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
        expect(mount(GalleryView).find('h2').text()).toBe('Tokens');
    });

    it('PWA-041 the appearance switch repaints the page without a reload', async () => {
        const wrapper = mount(TokensGallery);

        await wrapper.get('[role="radio"]:nth-child(3)').trigger('click');
        expect(document.documentElement.dataset.appearance).toBe('dark');
        expect(wrapper.get('[aria-checked="true"]').text()).toBe('dark');

        await wrapper.get('[role="radio"]:nth-child(1)').trigger('click');
        expect(document.documentElement.dataset.appearance).toBeUndefined();
    });
});

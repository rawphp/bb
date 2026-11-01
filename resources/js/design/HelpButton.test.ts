import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import GalleryView from '@/features/dev/GalleryView.vue';
import HelpButton from './HelpButton.vue';
import HelpButtonGallery from './gallery/HelpButton.gallery.vue';

const props = { title: 'Calorie target', message: 'How we set your daily calories.' };

function open(extra: Record<string, unknown> = {}, options: Record<string, unknown> = {}) {
    const wrapper = mount(HelpButton, { props: { ...props, ...extra }, attachTo: document.body, ...options });
    return wrapper.get('button[aria-label="Help"]').trigger('click').then(() => wrapper);
}

describe('HelpButton', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('UI-030 the ? icon is a labelled button with the topic as its description and a 48px target', () => {
        const button = mount(HelpButton, { props }).get('button');

        expect(button.attributes('aria-label')).toBe('Help');
        expect(button.attributes('aria-description')).toBe('Calorie target');
        expect(button.attributes('aria-haspopup')).toBe('dialog');
        expect(button.attributes('aria-expanded')).toBe('false');
        expect(button.classes()).toEqual(expect.arrayContaining(['min-h-tap', 'min-w-tap', 'text-accent']));
        expect(button.find('svg').attributes('aria-hidden')).toBe('true');
    });

    it('UI-009 a tap opens a sheet with the title as its heading, the message and Read more', async () => {
        const wrapper = await open({ href: 'https://example.com/learn' });

        const dialog = wrapper.get('[role="dialog"]');
        expect(dialog.attributes('aria-modal')).toBe('true');
        const heading = dialog.get('h2');
        expect(heading.text()).toBe('Calorie target');
        expect(dialog.attributes('aria-labelledby')).toBe(heading.attributes('id'));
        expect(dialog.text()).toContain('How we set your daily calories.');
        expect(wrapper.get('button[aria-label="Help"]').attributes('aria-expanded')).toBe('true');

        const link = dialog.get('a');
        expect(link.text()).toBe('Read more');
        expect(link.attributes('href')).toBe('https://example.com/learn');
        expect(link.classes()).toContain('min-h-tap');
        expect(document.activeElement).toBe(dialog.element);
    });

    it('UI-009 without a link, Read more closes the sheet and emits read-more', async () => {
        const wrapper = await open();

        await wrapper.get('[role="dialog"] button').trigger('click');

        expect(wrapper.emitted('read-more')).toHaveLength(1);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('LRN-003 an article route opens the Learn article in the app', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/:any(.*)*', component: { template: '<div />' } }],
        });
        const wrapper = await open({ to: '/learn/calorie-target' }, { global: { plugins: [router] } });

        expect(wrapper.get('[role="dialog"] a').attributes('href')).toBe('/learn/calorie-target');
    });

    it('UI-030 the dimmed backdrop and Escape close the sheet and return focus to the ? icon', async () => {
        const wrapper = await open();
        await wrapper.get('button[aria-label="Close help"]').trigger('click');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.get('button[aria-label="Help"]').element);

        await wrapper.get('button[aria-label="Help"]').trigger('click');
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('VIEW-004 empty: a sheet with no message shows the title and Read more only', async () => {
        const wrapper = await open({ message: '' });

        expect(wrapper.find('[role="dialog"] p').exists()).toBe(false);
        expect(wrapper.get('[role="dialog"]').text()).toContain('Read more');
    });

    it('VIEW-004 loading: the message is a busy placeholder announced as loading', async () => {
        const wrapper = await open({ state: 'loading' });

        const placeholder = wrapper.get('[aria-busy="true"]');
        expect(placeholder.text()).toBe('Loading');
        expect(wrapper.get('[role="dialog"]').text()).not.toContain(props.message);
    });

    it('VIEW-004 error: the message is replaced by an error in words, Read more stays', async () => {
        const wrapper = await open({ state: 'error' });

        expect(wrapper.get('[role="alert"]').text()).toBe("Couldn't load this help.");
        expect(wrapper.get('[role="dialog"]').text()).toContain('Read more');
    });

    it('VIEW-004 pending: the message is dimmed and says Waiting to sync (OFF-008)', async () => {
        const wrapper = await open({ state: 'pending' });

        expect(wrapper.get('.opacity-50').text()).toBe(props.message);
        expect(wrapper.get('[role="dialog"]').text()).toContain('Waiting to sync');
    });

    it('VIEW-004 disabled: the ? icon is disabled and does not open the sheet', async () => {
        const wrapper = mount(HelpButton, { props: { ...props, disabled: true } });

        const button = wrapper.get('button[aria-label="Help"]');
        expect(button.attributes('disabled')).toBeDefined();
        await button.trigger('click');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('VIEW-004 the gallery lists HelpButton with each state', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());
        expect(titles).toContain('HelpButton');

        const gallery = mount(HelpButtonGallery);
        expect(gallery.findAll('h3').map((h) => h.text())).toEqual([
            'default',
            'empty',
            'loading',
            'error',
            'pending',
            'disabled',
        ]);
        expect(gallery.findAllComponents(HelpButton)).toHaveLength(6);
    });
});

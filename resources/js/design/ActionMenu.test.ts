import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import ActionMenu from './ActionMenu.vue';

const items = [
    { key: 'a', title: 'Add day' },
    { key: 'b', title: 'Off', disabled: true },
    { key: 'c', title: 'End meso', detail: 'Ends today', destructive: true },
];

function menu() {
    return mount(ActionMenu, { props: { label: 'Mesocycle menu', items }, slots: { default: '…' }, attachTo: document.body });
}

const focused = () => (document.activeElement as HTMLElement | null)?.dataset.test;

describe('ActionMenu', () => {
    afterEach(() => (document.body.innerHTML = ''));

    it('UI-030 opens with focus on the first item and moves with the arrow keys, skipping disabled items', async () => {
        const wrapper = menu();
        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-test="menu"]').attributes('aria-expanded')).toBe('true');
        expect(focused()).toBe('item-a');
        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'ArrowDown' });
        expect(focused()).toBe('item-c');
        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'ArrowDown' });
        expect(focused()).toBe('item-a');
        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'ArrowUp' });
        expect(focused()).toBe('item-c');
        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'Home' });
        expect(focused()).toBe('item-a');
        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'End' });
        expect(focused()).toBe('item-c');
    });

    it('UI-030 closes on Escape and gives focus back to its button', async () => {
        const wrapper = menu();
        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.vm.$nextTick();
        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(focused()).toBe('menu');
    });

    it('UI-030 emits the chosen item, closes, and ignores a disabled one', async () => {
        const wrapper = menu();
        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="item-b"]').trigger('click');
        expect(wrapper.emitted('select')).toBeUndefined();

        await wrapper.find('[data-test="item-c"]').trigger('click');
        expect(wrapper.emitted('select')).toEqual([['c']]);
        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
    });

    it('UI-030 closes on a tap outside', async () => {
        const wrapper = menu();
        await wrapper.find('[data-test="menu"]').trigger('click');
        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
    });

    it('UI-030 opens from the keyboard with the down arrow', async () => {
        const wrapper = menu();
        await wrapper.find('[data-test="menu"]').trigger('keydown', { key: 'ArrowDown' });
        await wrapper.vm.$nextTick();

        expect(focused()).toBe('item-a');
    });

    it('VIEW-004 the gallery lists ActionMenu with every state', () => {
        const gallery = mount(GalleryView);
        expect(gallery.findAll('h2').map((h) => h.text())).toContain('ActionMenu');
        expect(gallery.findAll('[data-test="menu"]').map((b) => b.attributes('aria-label'))).toEqual(
            expect.arrayContaining(['default', 'empty', 'loading', 'error', 'pending', 'disabled'].map((state) => `Mesocycle menu (${state})`)),
        );
    });
});

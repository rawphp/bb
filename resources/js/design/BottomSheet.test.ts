import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import GalleryView from '@/features/dev/GalleryView.vue';
import BottomSheet from './BottomSheet.vue';

let mounted: VueWrapper | null = null;

function sheet(props: Record<string, unknown> = {}, body = '<p>Body</p>') {
    mounted = mount(BottomSheet, {
        props: { open: true, title: 'Copy foods', ...props },
        slots: { default: body },
        attachTo: document.body,
    });
    return mounted;
}

// jsdom has no PointerEvent, and Test Utils cannot set clientY on the MouseEvent it falls back to.
async function pointer(wrapper: VueWrapper, type: string, clientY: number) {
    wrapper.get('[data-drag-handle]').element.dispatchEvent(new MouseEvent(type, { clientY, bubbles: true }));
    await nextTick();
}

async function drag(wrapper: VueWrapper, dy: number) {
    await pointer(wrapper, 'pointerdown', 100);
    await pointer(wrapper, 'pointermove', 100 + dy);
    await pointer(wrapper, 'pointerup', 100 + dy);
}

describe('BottomSheet', () => {
    afterEach(() => {
        mounted?.unmount();
        mounted = null;
    });

    it('UI-005 lays out Cancel on the left, the title in the middle and Save on the right', () => {
        const header = sheet().get('header');
        const parts = header.findAll(':scope > *').map((el) => el.text());

        expect(parts).toEqual(['Cancel', 'Copy foods', 'Save']);
        expect(header.get('h2').text()).toBe('Copy foods');
    });

    it('UI-005 Cancel emits cancel and Save emits save', async () => {
        const wrapper = sheet();

        await wrapper.get('button[data-cancel]').trigger('click');
        await wrapper.get('button[data-save]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.emitted('save')).toHaveLength(1);
    });

    it('UI-005 a sheet that answers by its own choices has no Save, and the title stays in the middle', () => {
        const header = sheet({ saveTitle: null }).get('header');

        expect(header.find('button[data-save]').exists()).toBe(false);
        expect(header.findAll(':scope > *')).toHaveLength(3);
    });

    it('UI-005 the Save button can be renamed', () => {
        expect(sheet({ saveTitle: 'Add' }).get('button[data-save]').text()).toBe('Add');
    });

    it('UI-005 a closed sheet renders nothing', () => {
        expect(sheet({ open: false }).find('[role="dialog"]').exists()).toBe(false);
    });

    it('UI-030 the sheet is a modal dialog named by its title', () => {
        const dialog = sheet().get('[role="dialog"]');

        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(document.getElementById(dialog.attributes('aria-labelledby')!)!.textContent).toBe('Copy foods');
    });

    it('UI-030 focus moves into the sheet on open and back to the opener on close', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const wrapper = sheet({ open: false });
        await wrapper.setProps({ open: true });
        await nextTick();
        expect(wrapper.get('[role="dialog"]').element.contains(document.activeElement)).toBe(true);

        await wrapper.setProps({ open: false });
        expect(document.activeElement).toBe(opener);
        opener.remove();
    });

    it('PWA-023 the screen behind is dimmed, and a tap on it cancels', async () => {
        const wrapper = sheet();
        const scrim = wrapper.get('[data-scrim]');

        expect(scrim.classes()).toContain('bg-scrim');
        await scrim.trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('PWA-023 Escape cancels', async () => {
        const wrapper = sheet();

        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('PWA-023 dragging down far enough cancels, a short drag springs back', async () => {
        const wrapper = sheet();

        await drag(wrapper, 30);
        expect(wrapper.emitted('cancel')).toBeUndefined();
        expect(wrapper.get('[role="dialog"]').attributes('style') ?? '').not.toContain('translateY(30px)');

        await drag(wrapper, 200);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('PWA-023 the sheet follows the finger while dragging', async () => {
        const wrapper = sheet();

        await pointer(wrapper, 'pointerdown', 100);
        await pointer(wrapper, 'pointermove', 160);

        expect(wrapper.get('[role="dialog"]').attributes('style')).toContain('translateY(60px)');
    });

    it('PWA-023 opens at full height by default', () => {
        expect(sheet().get('[role="dialog"]').attributes('data-height')).toBe('full');
    });

    it('PWA-023 a sheet with half and full heights opens at half, drags up to full and down to half', async () => {
        const wrapper = sheet({ heights: ['half', 'full'] });
        const dialog = () => wrapper.get('[role="dialog"]');
        expect(dialog().attributes('data-height')).toBe('half');

        await drag(wrapper, -200);
        expect(dialog().attributes('data-height')).toBe('full');

        await drag(wrapper, 200);
        expect(dialog().attributes('data-height')).toBe('half');
        expect(wrapper.emitted('cancel')).toBeUndefined();

        await drag(wrapper, 200);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('UI-030 PWA-023 the grabber is a button that switches between half and full height', async () => {
        const wrapper = sheet({ heights: ['half', 'full'] });
        const grabber = wrapper.get('button[data-grabber]');

        expect(grabber.attributes('aria-label')).toBe('Expand sheet');
        await grabber.trigger('click');
        expect(wrapper.get('[role="dialog"]').attributes('data-height')).toBe('full');
        expect(grabber.attributes('aria-label')).toBe('Shrink sheet');
    });

    it('PWA-023 a sheet with one height has a grabber screen readers skip', () => {
        const wrapper = sheet();

        expect(wrapper.find('button[data-grabber]').exists()).toBe(false);
        expect(wrapper.get('[data-grabber]').attributes('aria-hidden')).toBe('true');
    });

    it('UI-022 a no-dismiss sheet ignores a tap outside, Escape and a drag down', async () => {
        const wrapper = sheet({ dismissible: false });

        await wrapper.get('[data-scrim]').trigger('click');
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
        await drag(wrapper, 400);

        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('UI-022 a no-dismiss sheet still cancels through its Cancel button', async () => {
        const wrapper = sheet({ dismissible: false });

        await wrapper.get('button[data-cancel]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('UI-022 a no-dismiss sheet with two heights still drags between them', async () => {
        const wrapper = sheet({ dismissible: false, heights: ['half', 'full'] });

        await drag(wrapper, -200);
        expect(wrapper.get('[role="dialog"]').attributes('data-height')).toBe('full');
        await drag(wrapper, 200);
        await drag(wrapper, 200);
        expect(wrapper.get('[role="dialog"]').attributes('data-height')).toBe('half');
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('VIEW-004 empty: a sheet with no content still shows its header', () => {
        const wrapper = sheet({}, '');

        expect(wrapper.get('header').text()).toContain('Copy foods');
        expect(wrapper.get('[data-content]').text()).toBe('');
    });

    it('VIEW-004 loading: the content is busy and Save cannot be pressed', async () => {
        const wrapper = sheet({ loading: true });
        const save = wrapper.get('button[data-save]');

        expect(wrapper.get('[data-content]').attributes('aria-busy')).toBe('true');
        expect(save.attributes('disabled')).toBeDefined();
        await save.trigger('click');
        expect(wrapper.emitted('save')).toBeUndefined();
        expect(sheet().get('[data-content]').attributes('aria-busy')).toBeUndefined();
    });

    it('VIEW-004 error: the message is announced under the header', () => {
        const wrapper = sheet({ error: "Couldn't copy the foods." });

        expect(wrapper.get('[role="alert"]').text()).toBe("Couldn't copy the foods.");
        expect(sheet().find('[role="alert"]').exists()).toBe(false);
    });

    it('OFF-009 pending: the sheet says it is waiting to sync, in text', () => {
        expect(sheet({ pending: true }).get('header').text()).toContain('Waiting to sync');
        expect(sheet().text()).not.toContain('Waiting to sync');
    });

    it('VIEW-004 disabled: Save and Cancel can each be disabled', async () => {
        const wrapper = sheet({ saveDisabled: true, cancelDisabled: true });

        await wrapper.get('button[data-save]').trigger('click');
        await wrapper.get('button[data-cancel]').trigger('click');

        expect(wrapper.get('button[data-save]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('button[data-cancel]').attributes('disabled')).toBeDefined();
        expect(wrapper.emitted('save')).toBeUndefined();
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('UI-011 the sheet is painted with token utilities only, and the scrim is a token', () => {
        const html = sheet().html();
        const css = readFileSync(join(import.meta.dirname, 'tokens.css'), 'utf8');

        expect(html).not.toMatch(/#[0-9a-f]{3,6}\b|rgb\(|var\(--/i);
        expect(html).toContain('bg-surface');
        expect(css).toMatch(/--bb-scrim:/);
        expect(css).toContain('--color-scrim: var(--bb-scrim);');
    });

    it('VIEW-004 the gallery lists BottomSheet with every state', () => {
        const gallery = mount(GalleryView);

        expect(gallery.findAll('h2').map((h) => h.text())).toContain('BottomSheet');
        expect(gallery.findAll('h3').map((h) => h.text())).toEqual(
            expect.arrayContaining(['Default', 'Half and full', 'No dismiss', 'Empty', 'Loading', 'Error', 'Pending', 'Disabled']),
        );
    });
});

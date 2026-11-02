import { DOMWrapper, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import GalleryView from '@/features/dev/GalleryView.vue';
import BottomSheet from './BottomSheet.vue';
import HelpButton from './HelpButton.vue';

const updates = vi.hoisted(() => {
    const release = vi.fn();
    return { release, holdUpdates: vi.fn(() => release) };
});

vi.mock('../app/updates', () => ({ holdUpdates: updates.holdUpdates }));

let mounted: VueWrapper | null = null;

function sheet(props: Record<string, unknown> = {}, body = '<p>Body</p>') {
    mounted?.unmount();
    mounted = mount(BottomSheet, {
        props: { open: true, title: 'Copy foods', ...props },
        slots: { default: body },
        attachTo: document.body,
    });
    return mounted;
}

// The sheet is teleported to <body>, outside the wrapper.
function find(selector: string): DOMWrapper<Element> {
    return new DOMWrapper(document.body.querySelector(selector));
}

function get(selector: string): DOMWrapper<Element> {
    const found = find(selector);
    expect(found.exists(), `${selector} is in the page`).toBe(true);
    return found;
}

function press(key: string, options: KeyboardEventInit = {}, target: Element = document.activeElement ?? document.body): KeyboardEvent {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...options });
    target.dispatchEvent(event);
    return event;
}

// jsdom has no PointerEvent, and Test Utils cannot set clientY on the MouseEvent it falls back to.
async function pointer(type: string, clientY: number) {
    get('[data-drag-handle]').element.dispatchEvent(new MouseEvent(type, { clientY, bubbles: true }));
    await nextTick();
}

async function drag(dy: number) {
    await pointer('pointerdown', 100);
    await pointer('pointermove', 100 + dy);
    await pointer('pointerup', 100 + dy);
}

describe('BottomSheet', () => {
    afterEach(() => {
        mounted?.unmount();
        mounted = null;
    });

    it('UI-005 lays out Cancel on the left, the title in the middle and Save on the right', () => {
        sheet();
        const header = get('header');
        const parts = header.findAll(':scope > *').map((el) => el.text());

        expect(parts).toEqual(['Cancel', 'Copy foods', 'Save']);
        expect(header.get('h2').text()).toBe('Copy foods');
    });

    it('UI-005 Cancel emits cancel and Save emits save', async () => {
        const wrapper = sheet();

        await get('button[data-cancel]').trigger('click');
        await get('button[data-save]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.emitted('save')).toHaveLength(1);
    });

    it('UI-005 a sheet that answers by its own choices has no Save, and the title stays in the middle', () => {
        sheet({ saveTitle: null });
        const header = get('header');

        expect(header.find('button[data-save]').exists()).toBe(false);
        expect(header.findAll(':scope > *')).toHaveLength(3);
    });

    it('UI-005 the Cancel button can be renamed, and still cancels', async () => {
        const wrapper = sheet({ cancelTitle: 'Not now' });

        expect(get('button[data-cancel]').text()).toBe('Not now');
        await get('button[data-cancel]').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('UI-005 the Save button can be renamed', () => {
        sheet({ saveTitle: 'Add' });
        expect(get('button[data-save]').text()).toBe('Add');
    });

    it('PWA-035 an open sheet holds updates until it closes or unmounts', async () => {
        updates.holdUpdates.mockClear();
        updates.release.mockClear();
        const wrapper = sheet({ open: false });
        expect(updates.holdUpdates).not.toHaveBeenCalled();

        await wrapper.setProps({ open: true });
        expect(updates.holdUpdates).toHaveBeenCalledTimes(1);
        expect(updates.release).not.toHaveBeenCalled();

        await wrapper.setProps({ open: false });
        expect(updates.release).toHaveBeenCalledTimes(1);

        await wrapper.setProps({ open: true });
        wrapper.unmount();
        mounted = null;
        expect(updates.holdUpdates).toHaveBeenCalledTimes(2);
        expect(updates.release).toHaveBeenCalledTimes(2);
    });

    it('UI-005 a closed sheet renders nothing', () => {
        sheet({ open: false });
        expect(find('[role="dialog"]').exists()).toBe(false);
    });

    it('PWA-023 the sheet renders on <body>, so a parent cannot clip or bury it', () => {
        const host = document.createElement('div');
        document.body.appendChild(host);
        mounted = mount(BottomSheet, { props: { open: true, title: 'Copy foods' }, attachTo: host });

        expect(host.querySelector('[role="dialog"]')).toBeNull();
        expect(host.querySelector('[data-scrim]')).toBeNull();
        expect(get('[role="dialog"]').exists()).toBe(true);
        mounted.unmount();
        mounted = null;
        host.remove();
    });

    it('UI-030 the sheet is a modal dialog named by its title', () => {
        sheet();
        const dialog = get('[role="dialog"]');

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
        expect(get('[role="dialog"]').element.contains(document.activeElement)).toBe(true);

        await wrapper.setProps({ open: false });
        expect(document.activeElement).toBe(opener);
        opener.remove();
    });

    it('PWA-023 the screen behind is dimmed, and a tap on it cancels', async () => {
        const wrapper = sheet();
        const scrim = get('[data-scrim]');

        expect(scrim.classes()).toContain('bg-scrim');
        await scrim.trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('PWA-023 Escape cancels', async () => {
        const wrapper = sheet();

        await get('[role="dialog"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('PWA-023 dragging down far enough cancels, a short drag springs back', async () => {
        const wrapper = sheet();

        await drag(30);
        expect(wrapper.emitted('cancel')).toBeUndefined();
        expect(get('[role="dialog"]').attributes('style') ?? '').not.toContain('translateY(30px)');

        await drag(200);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('PWA-023 the sheet follows the finger while dragging', async () => {
        sheet();

        await pointer('pointerdown', 100);
        await pointer('pointermove', 160);

        expect(get('[role="dialog"]').attributes('style')).toContain('translateY(60px)');
    });

    it('PWA-023 opens at full height by default', () => {
        sheet();
        expect(get('[role="dialog"]').attributes('data-height')).toBe('full');
    });

    it('PWA-023 a sheet with half and full heights opens at half, drags up to full and down to half', async () => {
        const wrapper = sheet({ heights: ['half', 'full'] });
        const dialog = () => get('[role="dialog"]');
        expect(dialog().attributes('data-height')).toBe('half');

        await drag(-200);
        expect(dialog().attributes('data-height')).toBe('full');

        await drag(200);
        expect(dialog().attributes('data-height')).toBe('half');
        expect(wrapper.emitted('cancel')).toBeUndefined();

        await drag(200);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('UI-030 PWA-023 the grabber is a button that switches between half and full height', async () => {
        sheet({ heights: ['half', 'full'] });
        const grabber = get('button[data-grabber]');

        expect(grabber.attributes('aria-label')).toBe('Expand sheet');
        await grabber.trigger('click');
        expect(get('[role="dialog"]').attributes('data-height')).toBe('full');
        expect(grabber.attributes('aria-label')).toBe('Shrink sheet');
    });

    it('PWA-023 a sheet with one height has a grabber screen readers skip', () => {
        sheet();

        expect(find('button[data-grabber]').exists()).toBe(false);
        expect(get('[data-grabber]').attributes('aria-hidden')).toBe('true');
    });

    it('UI-022 a no-dismiss sheet ignores a tap outside, Escape and a drag down', async () => {
        const wrapper = sheet({ dismissible: false });

        await get('[data-scrim]').trigger('click');
        await get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
        await drag(400);

        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('UI-022 a no-dismiss sheet still cancels through its Cancel button', async () => {
        const wrapper = sheet({ dismissible: false });

        await get('button[data-cancel]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('UI-022 a no-dismiss sheet with two heights still drags between them', async () => {
        const wrapper = sheet({ dismissible: false, heights: ['half', 'full'] });

        await drag(-200);
        expect(get('[role="dialog"]').attributes('data-height')).toBe('full');
        await drag(200);
        await drag(200);
        expect(get('[role="dialog"]').attributes('data-height')).toBe('half');
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('UI-030 Tab and Shift+Tab cycle through the sheet and never reach the page behind it', () => {
        const behind = document.createElement('button');
        document.body.appendChild(behind);
        sheet({}, '<input data-field />');
        const cancel = get('button[data-cancel]').element as HTMLElement;
        const field = get('[data-field]').element as HTMLElement;

        field.focus();
        expect(press('Tab').defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(cancel);

        expect(press('Tab', { shiftKey: true }).defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(field);

        // Focus fell to <body>, as after a tap on the dim: Tab comes back into the sheet.
        field.blur();
        expect(press('Tab', {}, document.body).defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(cancel);
        behind.remove();
    });

    it('UI-030 Escape still cancels once focus has left the sheet', () => {
        const wrapper = sheet();

        (document.activeElement as HTMLElement | null)?.blur();
        press('Escape', {}, document.body);

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('UI-030 with a sheet open over another, Escape cancels only the top one', async () => {
        const below = mount(BottomSheet, { props: { open: true, title: 'Below' }, attachTo: document.body });
        try {
            await nextTick();
            const wrapper = sheet({ title: 'Above' });
            await nextTick();

            press('Escape');

            expect(wrapper.emitted('cancel')).toHaveLength(1);
            expect(below.emitted('cancel')).toBeUndefined();
        } finally {
            below.unmount();
        }
    });

    it('UI-030 Escape and Tab in a HelpButton opened over the sheet stay with the HelpButton', async () => {
        const wrapper = mount(BottomSheet, {
            props: { open: true, title: 'Copy foods' },
            slots: { default: () => h(HelpButton, { title: 'Copy foods', message: 'Copies a meal to another day.' }) },
            attachTo: document.body,
        });
        mounted = wrapper;
        await nextTick();
        await get('button[aria-label="Help"]').trigger('click');
        await nextTick();
        const help = document.body.querySelectorAll<HTMLElement>('[role="dialog"]')[1]!;
        const close = help.querySelector<HTMLElement>('button[aria-label="Close help"]')!;

        close.focus();
        press('Tab');
        expect(help.contains(document.activeElement)).toBe(true);

        press('Escape');
        await nextTick();
        expect(wrapper.emitted('cancel')).toBeUndefined();
        expect(document.body.querySelectorAll('[role="dialog"]')).toHaveLength(1);
    });

    it('PWA-023 a tap that drifts a few pixels still reaches Cancel and Save; only a drag captures the pointer', async () => {
        const capture = vi.fn();
        HTMLElement.prototype.setPointerCapture = capture;
        try {
            sheet();

            await pointer('pointerdown', 100);
            await pointer('pointermove', 103);
            expect(capture).not.toHaveBeenCalled();
            expect(get('[role="dialog"]').attributes('style')).toBeUndefined();
            await pointer('pointerup', 103);
            expect(capture).not.toHaveBeenCalled();

            await pointer('pointerdown', 100);
            await pointer('pointermove', 120);
            await pointer('pointermove', 140);
            expect(capture).toHaveBeenCalledTimes(1);
        } finally {
            delete (HTMLElement.prototype as Partial<HTMLElement>).setPointerCapture;
        }
    });

    it('PWA-023 a drag the browser cancels springs back and does not close the sheet', async () => {
        const wrapper = sheet();

        await pointer('pointerdown', 100);
        await pointer('pointermove', 300);
        await pointer('pointercancel', 300);

        expect(wrapper.emitted('cancel')).toBeUndefined();
        expect(get('[role="dialog"]').attributes('style')).toBeUndefined();
    });

    it('PWA-023 UI-022 past its last stop the sheet resists the finger', async () => {
        sheet({ dismissible: false });
        await pointer('pointerdown', 100);
        await pointer('pointermove', 300);
        expect(get('[role="dialog"]').attributes('style')).toContain('translateY(50px)');
        await pointer('pointerup', 300);

        sheet({ heights: ['half', 'full'] });
        await drag(-200);
        await pointer('pointerdown', 300);
        await pointer('pointermove', 100);
        expect(get('[role="dialog"]').attributes('style')).toContain('translateY(-50px)');
    });

    it('PWA-023 a sheet reopens at its first height', async () => {
        const wrapper = sheet({ heights: ['half', 'full'] });
        await get('button[data-grabber]').trigger('click');
        expect(get('[role="dialog"]').attributes('data-height')).toBe('full');

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(get('[role="dialog"]').attributes('data-height')).toBe('half');
        expect(get('button[data-grabber]').attributes('aria-label')).toBe('Expand sheet');
    });

    it('VIEW-004 empty: a sheet with no content still shows its header', () => {
        sheet({}, '');

        expect(get('header').text()).toContain('Copy foods');
        expect(get('[data-content]').text()).toBe('');
    });

    it('VIEW-004 loading: the content is busy and Save cannot be pressed', async () => {
        const wrapper = sheet({ loading: true });
        const save = get('button[data-save]');

        expect(get('[data-content]').attributes('aria-busy')).toBe('true');
        expect(save.attributes('disabled')).toBeDefined();
        await save.trigger('click');
        expect(wrapper.emitted('save')).toBeUndefined();
        sheet();
        expect(get('[data-content]').attributes('aria-busy')).toBeUndefined();
    });

    it('VIEW-004 error: the message is announced under the header', () => {
        sheet({ error: "Couldn't copy the foods." });

        expect(get('[role="alert"]').text()).toBe("Couldn't copy the foods.");
        sheet();
        expect(find('[role="alert"]').exists()).toBe(false);
    });

    it('OFF-009 pending: the sheet says it is waiting to sync, in text', () => {
        sheet({ pending: true });
        expect(get('header').text()).toContain('Waiting to sync');
        sheet();
        expect(document.body.textContent).not.toContain('Waiting to sync');
    });

    it('VIEW-004 disabled: Save and Cancel can each be disabled', async () => {
        const wrapper = sheet({ saveDisabled: true, cancelDisabled: true });

        await get('button[data-save]').trigger('click');
        await get('button[data-cancel]').trigger('click');

        expect(get('button[data-save]').attributes('disabled')).toBeDefined();
        expect(get('button[data-cancel]').attributes('disabled')).toBeDefined();
        expect(wrapper.emitted('save')).toBeUndefined();
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('UI-011 the sheet is painted with token utilities only, and the scrim is a token', () => {
        sheet();
        const html = document.body.innerHTML;
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

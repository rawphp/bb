import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import ValueRow, { type RowAction, type ValueField } from './ValueRow.vue';

const fields: ValueField[] = [
    { id: 'weight', title: 'Weight', unit: 'kg', kind: 'decimal', value: '100' },
    { id: 'reps', title: 'Reps', kind: 'whole', value: '8' },
];
const menuActions: RowAction[] = [
    { id: 'add-below', title: 'Add set below' },
    { id: 'delete', title: 'Delete set', isDestructive: true },
];
const swipeActions: RowAction[] = [
    { id: 'edit', title: 'Edit' },
    { id: 'delete', title: 'Delete set', isDestructive: true },
];

function row(props: Record<string, unknown> = {}, options: Record<string, unknown> = {}) {
    return mount(ValueRow, { props: { label: 'Set 1', fields, menuActions, ...props }, ...options });
}

function pointer(type: string, clientX: number, clientY = 0, pointerType = 'touch'): PointerEvent {
    return new PointerEvent(type, { clientX, clientY, pointerId: 1, pointerType, bubbles: true });
}

async function swipe(wrapper: ReturnType<typeof row>, dx: number, dy = 0, pointerType = 'touch') {
    const el = wrapper.get('[data-row]').element;
    el.dispatchEvent(pointer('pointerdown', 200, 0, pointerType));
    el.dispatchEvent(pointer('pointermove', 200 + dx / 2, dy / 2, pointerType));
    el.dispatchEvent(pointer('pointermove', 200 + dx, dy, pointerType));
    el.dispatchEvent(pointer('pointerup', 200 + dx, dy, pointerType));
    await wrapper.vm.$nextTick();
}

describe('ValueRow', () => {
    it('UI-008 lays out the menu on the left, then the label and detail, then the numeric fields, then the trailing slot', () => {
        const wrapper = row({ detail: 'Straight' }, { slots: { trailing: '<button data-log>Log</button>' } });
        const children = wrapper.get('[data-row]').element.children;

        expect(children[0]!.querySelector('[aria-haspopup="menu"]')).not.toBeNull();
        expect(children[1]!.textContent).toContain('Set 1');
        expect(children[1]!.textContent).toContain('Straight');
        expect(children[2]!.querySelector('input')!.value).toBe('100');
        expect(children[3]!.querySelector('input')!.value).toBe('8');
        expect(children[4]!.matches('[data-log]')).toBe(true);
    });

    it('UI-008 fields are right-aligned, show their unit, and use their title as placeholder', () => {
        const [weight, reps] = row({ fields: fields.map((field) => ({ ...field, value: '' })) }).findAll('input');

        expect(weight!.classes()).toContain('text-right');
        expect(weight!.attributes('placeholder')).toBe('Weight');
        expect(weight!.element.closest('label')!.textContent).toContain('kg');
        expect(reps!.attributes('placeholder')).toBe('Reps');
    });

    it('PWA-014 whole fields open the number keypad and decimal fields the decimal one', () => {
        const [weight, reps] = row().findAll('input');

        expect(weight!.attributes('inputmode')).toBe('decimal');
        expect(reps!.attributes('inputmode')).toBe('numeric');
        expect(row({ fields: [{ id: 'g', title: 'Grams', value: '' }] }).get('input').attributes('inputmode')).toBe('decimal');
    });

    it('UI-008 typing emits each keystroke and leaving the field commits it', async () => {
        const wrapper = row();
        const reps = wrapper.findAll('input')[1]!;

        (reps.element as HTMLInputElement).value = '10';
        await reps.trigger('input');
        expect(wrapper.emitted('commit')).toBeUndefined();
        await reps.trigger('change');

        expect(wrapper.emitted('update:field')).toEqual([['reps', '10']]);
        expect(wrapper.emitted('commit')).toEqual([['reps', '10']]);
    });

    it('UI-020 the whole 48 px box focuses its field, not just its text', () => {
        const input = row().findAll('input')[0]!;
        const box = input.element.closest('label')!;

        expect(box.classList).toContain('min-h-tap');
        expect(input.classes()).toContain('min-h-tap');
    });

    it('UI-020 in gym mode every control is at least 48 px', () => {
        const wrapper = row({ gym: true });

        expect(wrapper.get('[data-row]').classes()).toContain('min-h-tap');
        expect(wrapper.get('[aria-haspopup="menu"]').classes()).toEqual(expect.arrayContaining(['min-h-tap', 'min-w-tap']));
        for (const input of wrapper.findAll('input')) {
            expect(input.classes()).toContain('min-h-tap');
        }
    });

    it('UI-030 every control has a screen-reader label naming the row', () => {
        const wrapper = row();

        expect(wrapper.findAll('input').map((input) => input.attributes('aria-label'))).toEqual(['Set 1 Weight, kg', 'Set 1 Reps']);
        expect(wrapper.get('[aria-haspopup="menu"]').attributes('aria-label')).toBe('Set 1 menu');
    });

    it('UI-008 the menu opens on tap, focuses its first item, and emits the picked action', async () => {
        const wrapper = row({}, { attachTo: document.body });
        const button = wrapper.get('[aria-haspopup="menu"]');

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        await button.trigger('click');
        await wrapper.vm.$nextTick();

        expect(button.attributes('aria-expanded')).toBe('true');
        const items = wrapper.findAll('[role="menuitem"]');
        expect(items.map((item) => item.text())).toEqual(['Add set below', 'Delete set']);
        expect(document.activeElement).toBe(items[0]!.element);
        expect(items[1]!.classes()).toContain('text-tone-warning');

        await items[1]!.trigger('click');
        expect(wrapper.emitted('action')).toEqual([['delete']]);
        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(document.activeElement).toBe(button.element);
        wrapper.unmount();
    });

    it('UI-030 Tab out of the open menu closes it', async () => {
        const wrapper = row({}, { attachTo: document.body });
        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.findAll('[role="menuitem"]')[0]!.trigger('keydown', { key: 'Tab' });

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('UI-030 arrow keys move through the menu, wrapping, and Escape closes it back onto the button', async () => {
        const wrapper = row({}, { attachTo: document.body });
        const button = wrapper.get('[aria-haspopup="menu"]');
        await button.trigger('click');
        await wrapper.vm.$nextTick();
        const items = wrapper.findAll('[role="menuitem"]');

        await items[0]!.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(items[1]!.element);
        await items[1]!.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(items[0]!.element);
        await items[0]!.trigger('keydown', { key: 'ArrowUp' });
        expect(document.activeElement).toBe(items[1]!.element);

        await items[1]!.trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(document.activeElement).toBe(button.element);
        wrapper.unmount();
    });

    it('UI-008 a tap outside the row closes the menu', async () => {
        const wrapper = row({}, { attachTo: document.body });
        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        await wrapper.vm.$nextTick();

        document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('UI-008 the menu opens outside the box that clips the sliding row, so it is not cut off', async () => {
        const wrapper = row({ swipeActions }, { attachTo: document.body });
        await wrapper.get('[aria-haspopup="menu"]').trigger('click');

        const menu = wrapper.get('[role="menu"]').element;
        for (let el = menu.parentElement; el && el !== document.body; el = el.parentElement) {
            expect(el.classList.contains('overflow-hidden')).toBe(false);
        }
        expect(wrapper.get('[data-row]').element.closest('.overflow-hidden')).not.toBeNull();
        wrapper.unmount();
    });

    it('UI-008 an open menu or revealed swipe actions close when the row turns loading, disabled or gym', async () => {
        const wrapper = row({ swipeActions }, { attachTo: document.body });
        const menuButton = () => wrapper.get('[aria-haspopup="menu"]');

        for (const busy of [{ loading: true }, { disabled: true }]) {
            await menuButton().trigger('click');
            expect(wrapper.find('[role="menu"]').exists()).toBe(true);
            await wrapper.setProps(busy);
            expect(wrapper.find('[role="menu"]').exists()).toBe(false);
            await wrapper.setProps({ loading: false, disabled: false });
        }

        for (const stop of [{ loading: true }, { disabled: true }, { gym: true }]) {
            await swipe(wrapper, -120);
            expect(wrapper.get('[data-row]').attributes('data-open')).toBe('true');
            await wrapper.setProps(stop);
            expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
            await wrapper.setProps({ loading: false, disabled: false, gym: false });
            expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
            expect(wrapper.get('[data-swipe-actions]').attributes('inert')).toBeDefined();
        }
        wrapper.unmount();
    });

    it('UI-008 a tap outside the row, on the row, or on the menu button closes the revealed swipe actions', async () => {
        const wrapper = row({ swipeActions }, { attachTo: document.body });
        const open = () => wrapper.get('[data-row]').attributes('data-open');

        await swipe(wrapper, -120);
        expect(open()).toBe('true');
        document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
        await wrapper.vm.$nextTick();
        expect(open()).toBeUndefined();

        await swipe(wrapper, -120);
        expect(open()).toBe('true');
        await swipe(wrapper, 0);
        expect(open()).toBeUndefined();

        await swipe(wrapper, -120);
        expect(open()).toBe('true');
        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        expect(open()).toBeUndefined();
        expect(wrapper.find('[role="menu"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('UI-008 a drift under the slop stays a tap; past it the row captures the pointer, so the drag ends in no click', async () => {
        const wrapper = row({ swipeActions });
        const capture = vi.fn();
        (wrapper.get('[data-row]').element as HTMLElement).setPointerCapture = capture;

        await swipe(wrapper, -5);
        expect(capture).not.toHaveBeenCalled();
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();

        await swipe(wrapper, -60);
        expect(capture).toHaveBeenCalledWith(1);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBe('true');
    });

    it('UI-008 with no actions there is no menu button', () => {
        expect(row({ menuActions: [] }).find('[aria-haspopup="menu"]').exists()).toBe(false);
    });

    it('UI-008 swiping left reveals the swipe actions without running one, and tapping one emits it', async () => {
        const wrapper = row({ swipeActions }, { attachTo: document.body });
        const tray = wrapper.get('[data-swipe-actions]');
        expect(tray.attributes('inert')).toBeDefined();
        expect(tray.attributes('aria-hidden')).toBe('true');

        await swipe(wrapper, -120);

        expect(wrapper.get('[data-row]').attributes('data-open')).toBe('true');
        expect(tray.attributes('inert')).toBeUndefined();
        expect(wrapper.emitted('action')).toBeUndefined();

        expect(wrapper.get('[data-row]').classes()).toContain('motion-reduce:transition-none');

        const button = tray.findAll('button')[1]!;
        (button.element as HTMLButtonElement).focus();
        await button.trigger('click');
        expect(wrapper.emitted('action')).toEqual([['delete']]);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
        expect(document.activeElement).toBe(wrapper.get('[aria-haspopup="menu"]').element);
        wrapper.unmount();
    });

    it('UI-008 a cancelled drag snaps the row back closed', async () => {
        const wrapper = row({ swipeActions });
        const el = wrapper.get('[data-row]').element as HTMLElement;

        el.dispatchEvent(pointer('pointerdown', 200));
        el.dispatchEvent(pointer('pointermove', 150));
        el.dispatchEvent(pointer('pointermove', 100));
        el.dispatchEvent(pointer('pointercancel', 100));
        el.dispatchEvent(pointer('pointerup', 100));
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
        expect(el.style.transform).toBe('');
    });

    it('UI-008 a short or vertical drag, or a mouse drag, does not reveal the swipe actions; swiping right closes them', async () => {
        const wrapper = row({ swipeActions });

        await swipe(wrapper, -20);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
        await swipe(wrapper, -60, 120);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
        await swipe(wrapper, -120, 0, 'mouse');
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();

        await swipe(wrapper, -120);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBe('true');
        await swipe(wrapper, 120);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
    });

    it('UI-008 every swipe action is also in the menu, once, so it can be reached without swiping', async () => {
        const wrapper = row({ swipeActions });
        await wrapper.get('[aria-haspopup="menu"]').trigger('click');

        expect(wrapper.findAll('[role="menuitem"]').map((item) => item.text())).toEqual(['Add set below', 'Delete set', 'Edit']);
        expect(row({ menuActions: [], swipeActions }).find('[aria-haspopup="menu"]').exists()).toBe(true);
    });

    it('UI-020 gym mode has no swipe gesture; its swipe actions stay in the menu', async () => {
        const wrapper = row({ swipeActions, gym: true });

        expect(wrapper.find('[data-swipe-actions]').exists()).toBe(false);
        await swipe(wrapper, -120);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();

        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        expect(wrapper.findAll('[role="menuitem"]').map((item) => item.text())).toContain('Edit');
    });

    it('VIEW-004 empty: empty fields show their titles as placeholders, and a row with no fields shows just its label', () => {
        const wrapper = row({ fields: fields.map((field) => ({ ...field, value: '' })) });

        expect(wrapper.findAll('input').map((input) => [input.element.value, input.attributes('placeholder')])).toEqual([
            ['', 'Weight'],
            ['', 'Reps'],
        ]);
        const bare = row({ fields: [], menuActions: [] });
        expect(bare.findAll('input')).toHaveLength(0);
        expect(bare.text()).toBe('Set 1');
    });

    it('VIEW-004 loading: the row is busy, its fields and menu are disabled, and it cannot be swiped', async () => {
        const wrapper = row({ loading: true, swipeActions });

        expect(wrapper.get('[data-row]').attributes('aria-busy')).toBe('true');
        for (const input of wrapper.findAll('input')) {
            expect(input.attributes('disabled')).toBeDefined();
        }
        expect(wrapper.get('[aria-haspopup="menu"]').attributes('disabled')).toBeDefined();
        await swipe(wrapper, -120);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
        expect(row().get('[data-row]').attributes('aria-busy')).toBeUndefined();
    });

    it('VIEW-004 error: the message is announced under the row and the fields are marked invalid and described by it', () => {
        const wrapper = row({ error: "Couldn't save the set." });
        const alert = wrapper.get('[role="alert"]');

        expect(alert.text()).toBe("Couldn't save the set.");
        for (const input of wrapper.findAll('input')) {
            expect(input.attributes('aria-invalid')).toBe('true');
            expect(input.attributes('aria-describedby')).toBe(alert.attributes('id'));
        }
        expect(row().find('[role="alert"]').exists()).toBe(false);
        expect(row().get('input').attributes('aria-invalid')).toBeUndefined();
    });

    it('OFF-009 pending: the row carries a pending mark in text, and its fields are described by it', () => {
        const wrapper = row({ pending: true });
        const input = wrapper.get('input');

        expect(wrapper.text()).toContain('Waiting to sync');
        expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toBe('Waiting to sync');
        expect(row().text()).not.toContain('Waiting to sync');
        expect(row().get('input').attributes('aria-describedby')).toBeUndefined();
    });

    it('OFF-009 pending with an error: the fields are described by both the pending mark and the message', () => {
        const wrapper = row({ pending: true, error: "Couldn't save the set." });
        const ids = wrapper.get('input').attributes('aria-describedby')!.split(' ');

        expect(ids.map((id) => wrapper.get(`#${id}`).text())).toEqual(['Waiting to sync', "Couldn't save the set."]);
    });

    it('VIEW-004 disabled: fields and menu are disabled and the row cannot be swiped', async () => {
        const wrapper = row({ disabled: true, swipeActions });

        for (const input of wrapper.findAll('input')) {
            expect(input.attributes('disabled')).toBeDefined();
        }
        expect(wrapper.get('[aria-haspopup="menu"]').attributes('disabled')).toBeDefined();
        await swipe(wrapper, -120);
        expect(wrapper.get('[data-row]').attributes('data-open')).toBeUndefined();
    });

    it('VIEW-004 the gallery lists ValueRow with every state', () => {
        const gallery = mount(GalleryView);
        const section = gallery.findAll('section').find((s) => s.get('h2').text() === 'ValueRow')!;

        expect(section.findAll('h3').map((h) => h.text())).toEqual(
            expect.arrayContaining(['Default', 'Gym', 'Empty', 'Loading', 'Error', 'Pending', 'Disabled']),
        );
    });

    it('UI-011 the row is painted with token utilities only', () => {
        const html = row({ swipeActions, pending: true, error: 'x' }).html();

        expect(html).not.toMatch(/#[0-9a-f]{3,6}\b|rgb\(|var\(--/i);
        expect(html).toContain('bg-surface');
        expect(html).toContain('text-accent');
    });
});

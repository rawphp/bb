import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import ChoiceList, { type Choice } from './ChoiceList.vue';
import PendingMark from './PendingMark.vue';
import Skeleton from './Skeleton.vue';

const choices: Choice<string>[] = [
    { id: 'cut', title: 'Lose fat', detail: 'About 0.5% of body weight a week', isRecommended: true },
    { id: 'maintain', title: 'Maintain' },
    { id: 'bulk', title: 'Build muscle', detail: 'Finish the current phase first', isEnabled: false },
];

function list(props: Record<string, unknown> = {}) {
    return mount(ChoiceList, { props: { label: 'Goal', choices, modelValue: null, ...props } });
}

describe('ChoiceList', () => {
    it('UI-006 draws one full-width row per choice inside a labelled radio group', () => {
        const wrapper = list();

        expect(wrapper.get('[role="radiogroup"]').attributes('aria-label')).toBe('Goal');
        expect(wrapper.findAll('[role="radio"]').map((row) => row.text())).toEqual([
            expect.stringContaining('Lose fat'),
            'Maintain',
            expect.stringContaining('Build muscle'),
        ]);
    });

    it('UI-006 marks only the selected row as checked', () => {
        const rows = list({ modelValue: 'maintain' }).findAll('[role="radio"]');

        expect(rows.map((row) => row.attributes('aria-checked'))).toEqual(['false', 'true', 'false']);
    });

    it('UI-006 picking a row emits its id', async () => {
        const wrapper = list({ modelValue: 'maintain' });

        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['cut']]);
    });

    it('UI-006 picking the selected row again emits nothing', async () => {
        const wrapper = list({ modelValue: 'maintain' });

        await wrapper.findAll('[role="radio"]')[1]!.trigger('click');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('UI-006 picking the selected row again while an error shows emits it, so a failed save can be retried', async () => {
        const wrapper = list({ modelValue: 'maintain', error: "Couldn't save your choice." });

        await wrapper.findAll('[role="radio"]')[1]!.trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['maintain']]);
    });

    it('UI-030 the group is one tab stop: the selected row, else the first row that can be picked', () => {
        const tabIndexes = (props: Record<string, unknown>) =>
            list(props)
                .findAll('[role="radio"]')
                .map((row) => row.attributes('tabindex'));

        expect(tabIndexes({ modelValue: 'maintain' })).toEqual(['-1', '0', '-1']);
        expect(tabIndexes({ modelValue: null })).toEqual(['0', '-1', '-1']);
        expect(tabIndexes({ modelValue: 'bulk' })).toEqual(['0', '-1', '-1']);
        expect(
            tabIndexes({ modelValue: null, choices: [{ ...choices[0]!, isEnabled: false }, ...choices.slice(1)] }),
        ).toEqual(['-1', '0', '-1']);
    });

    it.each([
        ['ArrowDown', 'cut', 'maintain'],
        ['ArrowRight', 'cut', 'maintain'],
        ['ArrowDown', 'maintain', 'cut'],
        ['ArrowUp', 'cut', 'maintain'],
        ['ArrowLeft', 'maintain', 'cut'],
    ])('UI-030 %s on %s moves focus to and picks %s, skipping rows that cannot be picked', async (key, from, to) => {
        const wrapper = mount(ChoiceList, {
            props: { label: 'Goal', choices, modelValue: from },
            attachTo: document.body,
        });
        const rows = wrapper.findAll('[role="radio"]');
        const index = (id: string) => choices.findIndex((choice) => choice.id === id);

        const event = new KeyboardEvent('keydown', { key, cancelable: true });
        rows[index(from)]!.element.dispatchEvent(event);
        await wrapper.vm.$nextTick();

        expect(event.defaultPrevented).toBe(true);
        expect(wrapper.emitted('update:modelValue')).toEqual([[to]]);
        expect(document.activeElement).toBe(rows[index(to)]!.element);
        wrapper.unmount();
    });

    it.each([
        ['ArrowUp', 'a'],
        ['ArrowLeft', 'a'],
        ['ArrowDown', 'c'],
        ['ArrowRight', 'c'],
    ])('UI-030 %s from the middle row picks %s', async (key, to) => {
        const abc: Choice<string>[] = ['a', 'b', 'c', 'd'].map((id) => ({ id, title: id.toUpperCase() }));
        const wrapper = mount(ChoiceList, { props: { label: 'Letter', choices: abc, modelValue: 'b' } });

        await wrapper.findAll('[role="radio"]')[1]!.trigger('keydown', { key });

        expect(wrapper.emitted('update:modelValue')).toEqual([[to]]);
    });

    it('UI-030 other keys, such as Tab, leave the selection and the browser default alone', async () => {
        const wrapper = list({ modelValue: 'cut' });

        const event = new KeyboardEvent('keydown', { key: 'Tab', cancelable: true });
        wrapper.findAll('[role="radio"]')[0]!.element.dispatchEvent(event);
        await wrapper.vm.$nextTick();

        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('UI-006 shows the Recommended tag only on a recommended row', () => {
        const rows = list().findAll('[role="radio"]');

        expect(rows[0]!.text()).toContain('Recommended');
        expect(rows[1]!.text()).not.toContain('Recommended');
    });

    it('UI-006 a disabled choice cannot be picked, and is never shown as selected', async () => {
        const wrapper = list({ modelValue: 'bulk' });
        const row = wrapper.findAll('[role="radio"]')[2]!;

        expect(row.attributes('disabled')).toBeDefined();
        expect(row.attributes('aria-checked')).toBe('false');
        await row.trigger('click');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('UI-030 each row names its title, says when it is recommended, and is described by its detail', () => {
        const wrapper = list();
        const [recommended, plain] = wrapper.findAll('[role="radio"]');

        expect(recommended!.attributes('aria-label')).toBe('Lose fat, recommended');
        expect(plain!.attributes('aria-label')).toBe('Maintain');
        const detail = wrapper.get(`#${recommended!.attributes('aria-describedby')}`);
        expect(detail.text()).toBe('About 0.5% of body weight a week');
        expect(plain!.attributes('aria-describedby')).toBeUndefined();
    });

    it('VIEW-004 empty: no choices draws no rows', () => {
        const wrapper = list({ choices: [] });

        expect(wrapper.find('[role="radiogroup"]').exists()).toBe(true);
        expect(wrapper.findAll('[role="radio"]')).toHaveLength(0);
    });

    it('VIEW-004 loading: the group is busy, placeholder rows are hidden from screen readers, and nothing can be picked', async () => {
        const empty = list({ choices: [], loading: true });
        const group = empty.get('[role="radiogroup"]');
        expect(group.attributes('aria-busy')).toBe('true');
        expect(empty.findAll('[aria-hidden="true"][data-placeholder]').length).toBeGreaterThan(0);

        const filled = list({ loading: true });
        expect(filled.findAll('[data-placeholder]')).toHaveLength(0);
        expect(filled.findAll('[role="radio"]')[0]!.attributes('disabled')).toBeDefined();
        expect(filled.findAll('[role="radio"]')[0]!.classes()).toContain('opacity-60');
        await filled.findAll('[role="radio"]')[0]!.trigger('click');
        expect(filled.emitted('update:modelValue')).toBeUndefined();
    });

    it('OFF-009 pending: a selected row with a detail is described by both the detail and the pending mark', () => {
        const wrapper = list({ modelValue: 'cut', pending: true });
        const ids = wrapper.findAll('[role="radio"]')[0]!.attributes('aria-describedby')!.split(' ');

        expect(ids.map((id) => wrapper.get(`#${id}`).text())).toEqual(['About 0.5% of body weight a week', 'Waiting to sync']);
    });

    it('VIEW-004 error: the message is announced under the rows', () => {
        const wrapper = list({ error: "Couldn't save your choice." });

        expect(wrapper.get('[role="alert"]').text()).toBe("Couldn't save your choice.");
        expect(list().find('[role="alert"]').exists()).toBe(false);
    });

    it('OFF-009 pending: the selected row carries a pending mark in text, not colour alone', () => {
        const rows = list({ modelValue: 'maintain', pending: true }).findAll('[role="radio"]');

        expect(rows[1]!.text()).toContain('Waiting to sync');
        expect(rows[1]!.find(`#${rows[1]!.attributes('aria-describedby')}`).text()).toBe('Waiting to sync');
        expect(rows[0]!.text()).not.toContain('Waiting to sync');
        expect(list({ modelValue: 'maintain' }).text()).not.toContain('Waiting to sync');
    });

    it('VIEW-004 disabled: every row is disabled and nothing can be picked', async () => {
        const wrapper = list({ disabled: true });

        for (const row of wrapper.findAll('[role="radio"]')) {
            expect(row.attributes('disabled')).toBeDefined();
        }
        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('VIEW-004 the gallery lists ChoiceList with every state', () => {
        const gallery = mount(GalleryView);
        const titles = gallery.findAll('h2').map((h) => h.text());
        expect(titles).toContain('ChoiceList');

        const states = gallery.findAll('h3').map((h) => h.text());
        expect(states).toEqual(expect.arrayContaining(['Default', 'Empty', 'Loading', 'Error', 'Pending', 'Disabled']));
    });

    it('PWA-024 OFF-009 loading rows and the pending mark are the shared Skeleton and PendingMark', () => {
        expect(list({ choices: [], loading: true }).findComponent(Skeleton).props('shape')).toBe('row');
        expect(list({ modelValue: 'cut', pending: true }).findAllComponents(PendingMark)).toHaveLength(1);
    });

    it('UI-011 rows are painted with token utilities only', () => {
        const html = list({ modelValue: 'cut' }).html();

        expect(html).not.toMatch(/#[0-9a-f]{3,6}\b|rgb\(|var\(--/i);
        expect(html).toContain('bg-surface');
        expect(html).toContain('border-accent');
        expect(html).toContain('stroke-on-accent');
    });
});

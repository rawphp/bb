import { mount } from '@vue/test-utils';
import { describe, expect, expectTypeOf, it } from 'vitest';
import type { WeekShowOutput } from '@/contracts/week_show';
import GalleryView from '@/features/dev/GalleryView.vue';
import weekShow from '../../../tests/fixtures/screens/week_show.json';
import weekShowEmpty from '../../../tests/fixtures/screens/week_show.empty.json';
import WeekStrip, { type WeekStripDay } from './WeekStrip.vue';

const days: WeekStripDay[] = [
    { date: '2026-10-26', letter: 'M', number: '26', calories_text: '–', is_today: false, is_selected: false, is_complete: false, has_training: false, is_before_plan: true },
    { date: '2026-10-27', letter: 'T', number: '27', calories_text: '2600', is_today: false, is_selected: false, is_complete: true, has_training: true, is_before_plan: false },
    { date: '2026-10-28', letter: 'W', number: '28', calories_text: '2100', is_today: true, is_selected: false, is_complete: false, has_training: false, is_before_plan: false },
    { date: '2026-10-29', letter: 'T', number: '29', calories_text: '2600', is_today: false, is_selected: true, is_complete: false, has_training: true, is_before_plan: false },
];

function strip(props: Record<string, unknown> = {}) {
    return mount(WeekStrip, { props: { days, ...props } });
}

function buttons(wrapper: ReturnType<typeof strip>) {
    return wrapper.findAll('button');
}

async function drag(wrapper: ReturnType<typeof strip>, dx: number, dy: number) {
    const target = wrapper.get('[role="group"]').element;
    target.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, clientX: 100, clientY: 100 }));
    target.dispatchEvent(new MouseEvent('pointerup', { bubbles: true, clientX: 100 + dx, clientY: 100 + dy }));
    await wrapper.vm.$nextTick();
}

describe('WeekStrip', () => {
    it('UI-001 draws each day with its letter, date and calories, and "–" when nothing is planned', () => {
        const texts = buttons(strip()).map((day) =>
            day
                .findAll('span')
                .map((part) => part.text())
                .join(' '),
        );

        expect(texts).toEqual(['M 26 –', 'T 27 2600', 'W 28 2100', 'T 29 2600']);
    });

    it('UI-001 shows the completion dot filled on a completed day, and hides it before the plan (SCH-021)', () => {
        const [before, complete, open] = buttons(strip());

        expect(before!.get('[data-completion]').classes()).toContain('invisible');
        expect(complete!.get('[data-completion]').attributes('data-completion')).toBe('complete');
        expect(complete!.get('[data-completion]').classes()).toContain('text-tone-good');
        expect(open!.get('[data-completion]').attributes('data-completion')).toBe('open');
        expect(open!.get('[data-completion]').classes()).not.toContain('invisible');
    });

    it('UI-001 shows the training icon only on a training day after the plan starts', () => {
        const visible = (day: WeekStripDay) =>
            !strip({ days: [day] }).get('[data-training]').classes().includes('invisible');

        expect(visible(days[1]!)).toBe(true);
        expect(visible(days[2]!)).toBe(false);
        expect(visible({ ...days[0]!, has_training: true })).toBe(false);
    });

    it('SCH-020 fills the selected date with the accent, and rings today only when it is not selected', () => {
        const dates = buttons(strip()).map((day) => day.get('[data-date]').classes());

        expect(dates[3]).toContain('bg-accent');
        expect(dates[2]).toContain('ring-accent');
        expect(dates[2]).not.toContain('bg-accent');
        const selectedToday = strip({ days: [{ ...days[2]!, is_selected: true }] }).get('[data-date]').classes();
        expect(selectedToday).toContain('bg-accent');
        expect(selectedToday).not.toContain('ring-accent');
    });

    it('SCH-020 tapping a day emits its date', async () => {
        const wrapper = strip();

        await buttons(wrapper)[2]!.trigger('click');

        expect(wrapper.emitted('select')).toEqual([['2026-10-28']]);
    });

    it.each([
        [-60, 0, 1],
        [60, 10, -1],
    ])('SCH-020 a horizontal swipe of %ipx (%ipx down) emits %i for the week after or before', async (dx, dy, step) => {
        const wrapper = strip();

        await drag(wrapper, dx, dy);

        expect(wrapper.emitted('swipe')).toEqual([[step]]);
    });

    it.each([
        ['too short', 20, 0],
        ['too vertical', 60, 50],
    ])('SCH-020 a drag that is %s is not a swipe', async (_, dx, dy) => {
        const wrapper = strip();

        await drag(wrapper, dx, dy);

        expect(wrapper.emitted('swipe')).toBeUndefined();
    });

    it('SCH-020 the click that ends a swipe does not also pick a day', async () => {
        const wrapper = strip();

        await drag(wrapper, -60, 0);
        await buttons(wrapper)[1]!.trigger('click');
        await buttons(wrapper)[1]!.trigger('click');

        expect(wrapper.emitted('select')).toEqual([['2026-10-27']]);
    });

    it('UI-030 each day has one spoken label, the selected day is pressed and today is the current date', () => {
        const labels = buttons(strip()).map((day) => day.attributes('aria-label'));

        expect(labels).toEqual([
            'M 26',
            'T 27, 2600 calories, completed, training day',
            'W 28, 2100 calories, today, not completed',
            'T 29, 2600 calories, not completed, training day',
        ]);
        expect(buttons(strip()).map((day) => day.attributes('aria-pressed'))).toEqual(['false', 'false', 'false', 'true']);
        expect(buttons(strip()).map((day) => day.attributes('aria-current'))).toEqual([undefined, undefined, 'date', undefined]);
        expect(strip().get('[role="group"]').attributes('aria-label')).toBe('Week');
    });

    it("UI-001 takes week_show's days as the server sends them", () => {
        expectTypeOf<WeekShowOutput['days']>().toExtend<WeekStripDay[]>();

        const week = mount(WeekStrip, { props: { days: weekShow.days } });
        expect(buttons(week).map((day) => day.attributes('aria-label'))[4]).toBe(
            'W 23, 2600 calories, today, not completed, training day',
        );
        const before = mount(WeekStrip, { props: { days: weekShowEmpty.days } });
        expect(buttons(before).map((day) => day.attributes('aria-label'))[0]).toBe('S 19');
    });

    it('VIEW-004 empty: no days draws no buttons', () => {
        const wrapper = strip({ days: [] });

        expect(wrapper.find('[role="group"]').exists()).toBe(true);
        expect(buttons(wrapper)).toHaveLength(0);
    });

    it('VIEW-004 loading: the strip is busy, shows seven placeholder days hidden from screen readers, and nothing can be picked', async () => {
        const empty = strip({ days: [], loading: true });
        expect(empty.get('[role="group"]').attributes('aria-busy')).toBe('true');
        expect(empty.findAll('[aria-hidden="true"][data-placeholder]')).toHaveLength(7);

        const loaded = strip({ loading: true });
        expect(loaded.findAll('[data-placeholder]')).toHaveLength(0);
        expect(buttons(loaded).every((day) => day.attributes('disabled') !== undefined)).toBe(true);
        await drag(loaded, -60, 0);
        expect(loaded.emitted('swipe')).toBeUndefined();
    });

    it('VIEW-004 error: the message is announced and the days stay', () => {
        const wrapper = strip({ error: "Couldn't load this week." });

        expect(wrapper.get('[role="alert"]').text()).toBe("Couldn't load this week.");
        expect(buttons(wrapper)).toHaveLength(4);
    });

    it('VIEW-004 pending: calories are dimmed and every day says it is waiting to sync (OFF-008)', () => {
        const wrapper = strip({ pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
        expect(buttons(wrapper)[1]!.get('[data-calories]').classes()).toContain('opacity-50');
        expect(buttons(wrapper).every((day) => day.attributes('aria-label')!.endsWith(', waiting to sync'))).toBe(true);
    });

    it('VIEW-004 disabled: days cannot be picked and swipes are ignored', async () => {
        const wrapper = strip({ disabled: true });

        expect(buttons(wrapper).every((day) => day.attributes('disabled') !== undefined)).toBe(true);
        await drag(wrapper, -60, 0);
        expect(wrapper.emitted('swipe')).toBeUndefined();
    });

    it('VIEW-004 the gallery lists WeekStrip with every state', () => {
        const gallery = mount(GalleryView);
        const section = gallery.findAll('section').find((s) => s.get('h2').text() === 'WeekStrip');

        expect(section).toBeDefined();
        expect(section!.findAll('h3').map((h) => h.text())).toEqual(
            expect.arrayContaining(['Empty', 'Loading', 'Error', 'Pending', 'Disabled']),
        );
    });
});

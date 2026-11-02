import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import ItemCard, { type ItemCardStatus } from './ItemCard.vue';
import ItemCardGallery from './gallery/ItemCard.gallery.vue';

const checkbox: ItemCardStatus = { kind: 'checkbox', isOn: false, label: 'Breakfast eaten' };

function card(props: Record<string, unknown> = {}, body: string | null = 'Oats 80 g') {
    return mount(ItemCard, {
        props: { title: 'Breakfast', time: '07:30', status: checkbox, ...props },
        slots: { icon: '<svg data-icon />', ...(body === null ? {} : { default: `<p>${body}</p>` }) },
    });
}

function checkIn(state: 'not_checked_in' | 'on_track' | 'under' | 'over', label: string) {
    return card({ status: { kind: 'check-in', state, label, mealName: 'Lunch' } });
}

describe('ItemCard', () => {
    it('UI-002 lays out an icon, a heading title, the time on the right, the status control and the body', () => {
        const wrapper = card();

        const article = wrapper.get('article');
        const heading = article.get('h3');
        expect(heading.text()).toBe('Breakfast');
        expect(article.attributes('aria-labelledby')).toBe(heading.attributes('id'));
        expect(article.classes()).toEqual(expect.arrayContaining(['bg-surface', 'rounded-2xl']));

        const icon = article.get('[data-icon]').element.parentElement!;
        expect(icon.getAttribute('aria-hidden')).toBe('true');
        expect(icon.classList).toContain('text-accent');

        const header = heading.element.parentElement!;
        expect([...header.children].map((el) => el.tagName)).toEqual(['SPAN', 'H3', 'SPAN', 'BUTTON']);
        expect(header.children[2]?.textContent).toBe('07:30');
        expect(article.text()).toContain('Oats 80 g');
    });

    it('UI-030 the meal checkbox is a labelled checkbox with a 48px target that emits action', async () => {
        const wrapper = card();

        const box = wrapper.get('[role="checkbox"]');
        expect(box.attributes('aria-label')).toBe('Breakfast eaten');
        expect(box.attributes('aria-checked')).toBe('false');
        expect(box.classes()).toEqual(expect.arrayContaining(['min-h-tap', 'min-w-tap', 'text-secondary-text']));
        expect(box.find('svg').attributes('aria-hidden')).toBe('true');
        expect(box.find('path').exists()).toBe(false);

        await box.trigger('click');
        expect(wrapper.emitted('action')).toHaveLength(1);
    });

    it('UI-030 a ticked meal checkbox is checked and drawn with a tick in the accent', () => {
        const box = card({ status: { ...checkbox, isOn: true } }).get('[role="checkbox"]');

        expect(box.attributes('aria-checked')).toBe('true');
        expect(box.classes()).toContain('text-accent');
        expect(box.find('path').exists()).toBe(true);
    });

    it('MEAL-047 the check-in box shows each state with its own shape and the server label', () => {
        const shapes = (['not_checked_in', 'on_track', 'under', 'over'] as const).map((state) => {
            const box = checkIn(state, `label ${state}`).get('[role="checkbox"]');
            expect(box.attributes('aria-label')).toBe('Lunch eaten');
            expect(box.attributes('aria-description')).toBe(`label ${state}`);
            expect(box.attributes('aria-checked')).toBe(state === 'not_checked_in' ? 'false' : 'true');
            return { color: box.classes().find((c) => c.startsWith('text-')), d: box.find('path').exists() ? box.get('path').attributes('d') : null };
        });

        expect(shapes.map((s) => s.color)).toEqual(['text-secondary-text', 'text-tone-good', 'text-tone-warning', 'text-tone-warning']);
        expect(shapes[0]?.d).toBeNull();
        expect(new Set(shapes.slice(1).map((s) => s.d)).size).toBe(3);
    });

    it('MEAL-040 tapping the check-in box emits action', async () => {
        const wrapper = checkIn('not_checked_in', 'Not checked in');

        await wrapper.get('[role="checkbox"]').trigger('click');
        expect(wrapper.emitted('action')).toHaveLength(1);
    });

    it('UI-002 a workout card has a Start button that emits action', async () => {
        const wrapper = card({ status: { kind: 'button', title: 'Start' } });

        const button = wrapper.get('button');
        expect(button.text()).toBe('Start');
        expect(button.classes()).toEqual(expect.arrayContaining(['min-h-tap', 'text-accent']));
        await button.trigger('click');
        expect(wrapper.emitted('action')).toHaveLength(1);
    });

    it('UI-002 a weigh-in card shows its value, and a busy period has no status control', () => {
        const weighIn = card({ status: { kind: 'value', text: '82.4 kg' } });
        expect(weighIn.find('button').exists()).toBe(false);
        expect(weighIn.get('.tabular-nums').text()).toBe('82.4 kg');

        const busy = card({ status: undefined, time: '09:00–17:00' });
        expect(busy.find('button').exists()).toBe(false);
        expect(busy.text()).toContain('09:00–17:00');
    });

    it('VIEW-004 empty: a card with no body shows the header only', () => {
        const wrapper = card({}, null);

        expect(wrapper.get('article').element.children).toHaveLength(1);
        expect(wrapper.find('[role="checkbox"]').exists()).toBe(true);
    });

    it('VIEW-004 loading: the body is a busy placeholder announced as loading, with no control', () => {
        const wrapper = card({ state: 'loading' });

        expect(wrapper.get('article').attributes('aria-busy')).toBe('true');
        expect(wrapper.get('.sr-only').text()).toBe('Loading');
        expect(wrapper.text()).not.toContain('Oats 80 g');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('VIEW-004 error: the body is replaced by an error in words, with no control', () => {
        const wrapper = card({ state: 'error' });

        expect(wrapper.get('[role="alert"]').text()).toBe("Couldn't load this.");
        expect(wrapper.text()).not.toContain('Oats 80 g');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('VIEW-004 pending: the body and value are dimmed and the card says Waiting to sync (OFF-008)', () => {
        const meal = card({ state: 'pending' });
        expect(meal.get('.opacity-50 p').text()).toBe('Oats 80 g');
        expect(meal.text()).toContain('Waiting to sync');

        const weighIn = card({ state: 'pending', status: { kind: 'value', text: '82.4 kg' } });
        expect(weighIn.get('.tabular-nums').classes()).toContain('opacity-50');
    });

    it('VIEW-004 disabled: the status control is disabled and emits nothing', async () => {
        for (const status of [checkbox, { kind: 'button', title: 'Start' }]) {
            const wrapper = card({ status, disabled: true });
            const button = wrapper.get('button');
            expect(button.attributes('disabled')).toBeDefined();
            await button.trigger('click');
            expect(wrapper.emitted('action')).toBeUndefined();
        }
    });

    it('VIEW-004 the gallery lists ItemCard with each kind and state', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());
        expect(titles).toContain('ItemCard');

        const gallery = mount(ItemCardGallery);
        expect(gallery.findAll('figcaption').map((c) => c.text())).toEqual([
            'meal',
            'meal check-in',
            'workout',
            'weigh-in',
            'busy period',
            'empty',
            'loading',
            'error',
            'pending',
            'disabled',
        ]);
        expect(gallery.findAllComponents(ItemCard)).toHaveLength(10);
    });
});

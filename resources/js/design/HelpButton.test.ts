import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import GalleryView from '@/features/dev/GalleryView.vue';
import HelpButton from './HelpButton.vue';
import HelpButtonGallery from './gallery/HelpButton.gallery.vue';

const props = { title: 'Calorie target', message: 'How we set your daily calories.' };

let mounted: VueWrapper | undefined;

async function open(extra: Record<string, unknown> = {}, options: Record<string, unknown> = {}) {
    mounted = mount(HelpButton, { props: { ...props, ...extra }, attachTo: document.body, ...options });
    await mounted.get('button[aria-label="Help"]').trigger('click');
    return mounted;
}

// The sheet is teleported to <body>, outside the wrapper.
function dialog(): HTMLElement | null {
    return document.body.querySelector<HTMLElement>('[role="dialog"]');
}

function icon(wrapper: VueWrapper): HTMLElement {
    return wrapper.get('button[aria-label="Help"]').element as HTMLElement;
}

function press(target: Element, key: string, shiftKey = false): KeyboardEvent {
    const event = new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true });
    target.dispatchEvent(event);
    return event;
}

describe('HelpButton', () => {
    afterEach(() => {
        mounted?.unmount();
        mounted = undefined;
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

    it('UI-009 a class and attributes from the screen land on the ? icon', () => {
        const wrapper = mount(HelpButton, { props, attrs: { class: 'ml-auto', 'data-test': 'help' } });

        const button = wrapper.get('button[aria-label="Help"]');
        expect(button.classes()).toEqual(expect.arrayContaining(['ml-auto', 'min-h-tap']));
        expect(button.attributes('data-test')).toBe('help');
    });

    it('UI-009 a tap opens a sheet on <body> with the title as its heading, the message and Read more', async () => {
        const wrapper = await open({ href: 'https://example.com/learn' });

        const sheet = dialog()!;
        expect(sheet.closest('[data-help-overlay]')!.parentElement).toBe(document.body);
        expect(sheet.getAttribute('aria-modal')).toBe('true');
        const heading = sheet.querySelector('h2')!;
        expect(heading.textContent).toBe('Calorie target');
        expect(sheet.getAttribute('aria-labelledby')).toBe(heading.id);
        expect(sheet.textContent).toContain('How we set your daily calories.');
        expect(icon(wrapper).getAttribute('aria-expanded')).toBe('true');

        const link = sheet.querySelector('a')!;
        expect(link.textContent).toBe('Read more');
        expect(link.getAttribute('href')).toBe('https://example.com/learn');
        expect(link.classList).toContain('min-h-tap');
        expect(document.activeElement).toBe(sheet);
    });

    it('UI-009 without a link, Read more closes the sheet and emits read-more', async () => {
        const wrapper = await open();

        dialog()!.querySelector<HTMLElement>('button:not([aria-label])')!.click();
        await flushPromises();

        expect(wrapper.emitted('read-more')).toHaveLength(1);
        expect(dialog()).toBeNull();
        expect(document.activeElement).toBe(icon(wrapper));
    });

    it('LRN-003 an article route opens the Learn article in the app and closes the sheet', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/:any(.*)*', component: { template: '<div />' } }],
        });
        await router.push('/today');
        await router.isReady();
        await open({ to: '/learn/calorie-target' }, { global: { plugins: [router] } });

        const link = dialog()!.querySelector('a')!;
        expect(link.getAttribute('href')).toBe('/learn/calorie-target');
        link.click();
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/learn/calorie-target');
        expect(dialog()).toBeNull();
    });

    it('UI-030 Close help, the dimmed backdrop and Escape close the sheet and return focus to the ? icon', async () => {
        const wrapper = await open();
        dialog()!.querySelector<HTMLElement>('button[aria-label="Close help"]')!.click();
        await flushPromises();
        expect(dialog()).toBeNull();
        expect(document.activeElement).toBe(icon(wrapper));

        await wrapper.get('button[aria-label="Help"]').trigger('click');
        const scrim = document.body.querySelector<HTMLElement>('[data-scrim]')!;
        expect(scrim.getAttribute('aria-hidden')).toBe('true');
        expect(scrim.classList).toContain('bg-scrim');
        scrim.click();
        await flushPromises();
        expect(dialog()).toBeNull();
        expect(document.activeElement).toBe(icon(wrapper));

        await wrapper.get('button[aria-label="Help"]').trigger('click');
        const readMore = dialog()!.querySelector<HTMLElement>('button:not([aria-label])')!;
        readMore.focus();
        press(readMore, 'Escape');
        await flushPromises();
        expect(dialog()).toBeNull();
        expect(document.activeElement).toBe(icon(wrapper));
    });

    it('UI-030 Tab and Shift+Tab stay inside the sheet', async () => {
        await open({ href: 'https://example.com/learn' });
        const sheet = dialog()!;
        const close = sheet.querySelector<HTMLElement>('button[aria-label="Close help"]')!;
        const readMore = sheet.querySelector<HTMLElement>('a')!;

        readMore.focus();
        expect(press(readMore, 'Tab').defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(close);

        expect(press(close, 'Tab', true).defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(readMore);

        sheet.focus();
        expect(press(sheet, 'Tab', true).defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(readMore);

        close.focus();
        expect(press(close, 'Tab').defaultPrevented).toBe(false);
    });

    it('VIEW-004 empty: a sheet with no message shows the title and Read more only', async () => {
        await open({ message: '' });

        expect(dialog()!.querySelector('p')).toBeNull();
        expect(dialog()!.textContent).toContain('Read more');
    });

    it('VIEW-004 loading: the message is a busy placeholder announced as loading', async () => {
        await open({ loading: true, pending: true });

        const placeholder = dialog()!.querySelector('[aria-busy="true"]')!;
        expect(placeholder.textContent!.trim()).toBe('Loading');
        expect(dialog()!.textContent).not.toContain(props.message);
        expect(dialog()!.textContent).not.toContain('Waiting to sync');
    });

    it('VIEW-004 error: the message is replaced by the error in words, Read more stays', async () => {
        await open({ error: "Couldn't load this help." });

        const alert = dialog()!.querySelector('[role="alert"]')!;
        expect(alert.textContent).toBe("Couldn't load this help.");
        expect(alert.classList).toContain('text-tone-warning');
        expect(dialog()!.textContent).not.toContain(props.message);
        expect(dialog()!.textContent).toContain('Read more');
    });

    it('VIEW-004 pending: the message is dimmed and says Waiting to sync (OFF-008)', async () => {
        await open({ pending: true });

        expect(dialog()!.querySelector('.opacity-50')!.textContent).toBe(props.message);
        expect(dialog()!.textContent).toContain('Waiting to sync');
    });

    it('OFF-008 pending with no message still says Waiting to sync', async () => {
        await open({ pending: true, message: '' });

        expect(dialog()!.textContent).toContain('Waiting to sync');
    });

    it('VIEW-004 disabled: the ? icon is disabled and does not open the sheet', async () => {
        const wrapper = mount(HelpButton, { props: { ...props, disabled: true }, attachTo: document.body });
        mounted = wrapper;

        const button = wrapper.get('button[aria-label="Help"]');
        expect(button.attributes('disabled')).toBeDefined();
        await button.trigger('click');
        expect(dialog()).toBeNull();
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
    it('UI-009 with opens set, a tap emits open for the screen\'s own sheet and shows none here', async () => {
        mounted = mount(HelpButton, { props: { ...props, opens: true, label: 'What RIR means' }, attachTo: document.body });
        await mounted.get('button[aria-label="What RIR means"]').trigger('click');

        expect(mounted.emitted('open')).toHaveLength(1);
        expect(dialog()).toBeNull();
    });
});

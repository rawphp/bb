import { mount, type VueWrapper } from '@vue/test-utils';
import { computed, nextTick, ref, type Ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PwaOverlay from './PwaOverlay.vue';

const state = vi.hoisted(() => ({}) as { banner: Ref<boolean>; sheet: Ref<boolean> });
const applyUpdate = vi.fn();
const dismissInstall = vi.fn();

vi.mock('./updates', () => {
    const banner = ref(false);
    state.banner = banner;

    return { updateBanner: computed(() => banner.value), applyUpdate: () => applyUpdate() };
});
vi.mock('./install', () => {
    const sheet = ref(false);
    state.sheet = sheet;

    return { installSheet: sheet, dismissInstall: () => dismissInstall() };
});

let wrapper: VueWrapper | null = null;

function overlay(): VueWrapper {
    wrapper = mount(PwaOverlay, { attachTo: document.body });
    return wrapper;
}

// The install sheet is a BottomSheet, teleported to <body>.
function inPage(selector: string): HTMLElement {
    const found = document.body.querySelector<HTMLElement>(selector);
    expect(found, `${selector} is in the page`).not.toBeNull();
    return found!;
}

describe('PwaOverlay', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        state.banner.value = false;
        state.sheet.value = false;
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
    });

    it('PWA-035 shows nothing until an update waits', async () => {
        const wrapper = overlay();
        expect(wrapper.text()).toBe('');

        state.banner.value = true;
        await nextTick();
        expect(wrapper.get('button').text()).toBe('Updated, tap to reload');
    });

    it('PWA-035 reloads only on a tap of the banner', async () => {
        state.banner.value = true;
        const wrapper = overlay();

        await wrapper.get('button').trigger('click');

        expect(applyUpdate).toHaveBeenCalledTimes(1);
    });

    it('PWA-003 the iPhone sheet shows Share then Add to Home Screen, and Not now dismisses it', async () => {
        state.sheet.value = true;
        overlay();
        await nextTick();

        const dialog = inPage('[role="dialog"]');
        expect(dialog.textContent?.replace(/\s+/g, ' ')).toContain('Tap Share, then Add to Home Screen');
        expect(dialog.querySelector('svg[aria-label="Share"]')).not.toBeNull();
        const notNow = inPage('[role="dialog"] button[data-cancel]');
        expect(notNow.textContent?.trim()).toBe('Not now');
        notNow.click();

        expect(dismissInstall).toHaveBeenCalledTimes(1);
    });

    it('PWA-003 the iPhone sheet is the shared BottomSheet, so a tap outside or Escape dismisses it', async () => {
        state.sheet.value = true;
        overlay();
        await nextTick();

        inPage('[data-scrim]').click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

        expect(dismissInstall).toHaveBeenCalledTimes(2);
    });
});

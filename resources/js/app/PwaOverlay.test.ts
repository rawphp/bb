import { mount } from '@vue/test-utils';
import { computed, nextTick, ref, type Ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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

describe('PwaOverlay', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        state.banner.value = false;
        state.sheet.value = false;
    });

    it('PWA-035 shows nothing until an update waits', async () => {
        const wrapper = mount(PwaOverlay);
        expect(wrapper.text()).toBe('');

        state.banner.value = true;
        await nextTick();
        expect(wrapper.get('button').text()).toBe('Updated, tap to reload');
    });

    it('PWA-035 reloads only on a tap of the banner', async () => {
        state.banner.value = true;
        const wrapper = mount(PwaOverlay);

        await wrapper.get('button').trigger('click');

        expect(applyUpdate).toHaveBeenCalledTimes(1);
    });

    it('PWA-003 the iPhone sheet shows Share then Add to Home Screen, and Not now dismisses it', async () => {
        state.sheet.value = true;
        const wrapper = mount(PwaOverlay);

        expect(wrapper.get('[role="dialog"]').text()).toContain('Share, then Add to Home Screen');
        await wrapper.get('[role="dialog"] button').trigger('click');

        expect(dismissInstall).toHaveBeenCalledTimes(1);
    });

    it('PWA-003 a tap outside the sheet dismisses it too', async () => {
        state.sheet.value = true;
        const wrapper = mount(PwaOverlay);

        wrapper.get('[role="dialog"]').element.parentElement!.dispatchEvent(new Event('click'));

        expect(dismissInstall).toHaveBeenCalledTimes(1);
    });
});

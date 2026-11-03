import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref, type Ref } from 'vue';
import { provideScreenTop, useScreenTop } from './screenTop';

function seen(provided?: Ref<boolean>): { top: Readonly<Ref<boolean>> | null } {
    const found: { top: Readonly<Ref<boolean>> | null } = { top: null };
    const Child = defineComponent({
        setup() {
            found.top = useScreenTop();
            return () => h('p');
        },
    });
    mount(
        defineComponent({
            setup() {
                if (provided) provideScreenTop(provided);
                return () => h(Child);
            },
        }),
    );
    return found;
}

describe('useScreenTop', () => {
    it('PWA-020 outside a tab stack there is no screen-top state', () => {
        expect(seen().top).toBeNull();
    });

    it('PWA-020 inside a tab stack it follows whether the screen is on show', async () => {
        const top = ref(true);
        const found = seen(top);

        expect(found.top?.value).toBe(true);
        top.value = false;
        await nextTick();
        expect(found.top?.value).toBe(false);
    });
});

import { inject, provide, readonly, watch, type InjectionKey, type Ref } from 'vue';

// Whether a screen in a tab's stack is the one on show: the top of the active tab.
// TabStack keeps every screen mounted (PWA-020), so a screen under a pushed one
// holds what it last read; it uses this to read again when it is back on top.
const SCREEN_TOP: InjectionKey<Readonly<Ref<boolean>>> = Symbol('screen-top');

/** TabStack's frame for one screen: tells the screen whether it is on show. */
export function provideScreenTop(top: Ref<boolean>): void {
    provide(SCREEN_TOP, readonly(top));
}

/** Calls `back` each time the screen comes back on show. Outside a tab stack it never does. */
export function onScreenReturn(back: () => void): void {
    const top = inject(SCREEN_TOP, null);
    if (top) watch(top, (now, before) => now && !before && back());
}

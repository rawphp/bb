<script lang="ts">
/** One choice in an ActionMenu. `detail` is a second line under the title. */
export interface ActionMenuItem {
    key: string;
    title: string;
    detail?: string | null;
    disabled?: boolean;
    destructive?: boolean;
}
</script>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId } from 'vue';

// A toolbar button that opens a menu of actions, as SwiftUI's Menu does: the mesocycle
// menu and the + menu. Keyboard use follows the menu pattern (UI-030): focus moves to the
// first item on open, arrow keys, Home and End move between items, Escape closes and
// focus goes back to the button. A tap outside or Tab closes it.
// `align` start opens the menu from the button's left edge, for a button at the left of the screen.
const props = withDefaults(defineProps<{ label: string; items: ActionMenuItem[]; disabled?: boolean; align?: 'start' | 'end' }>(), { disabled: false, align: 'end' });

const emit = defineEmits<{ select: [key: string] }>();

const open = ref(false);
const trigger = ref<HTMLButtonElement>();
const root = ref<HTMLElement>();
const itemEls = ref<HTMLButtonElement[]>([]);
const menuId = useId();

function enabled(): HTMLButtonElement[] {
    return itemEls.value.filter((el) => !el.disabled);
}

async function show(): Promise<void> {
    open.value = true;
    document.addEventListener('pointerdown', onOutside, true);
    await nextTick();
    enabled()[0]?.focus();
}

function hide(returnFocus: boolean): void {
    open.value = false;
    document.removeEventListener('pointerdown', onOutside, true);
    if (returnFocus) trigger.value?.focus();
}

function toggle(): void {
    if (open.value) hide(true);
    else void show();
}

function choose(item: ActionMenuItem): void {
    if (item.disabled) return;
    hide(true);
    emit('select', item.key);
}

function onOutside(event: Event): void {
    if (!root.value?.contains(event.target as Node)) hide(false);
}

function onKeydown(event: KeyboardEvent): void {
    const items = enabled();
    const index = items.indexOf(document.activeElement as HTMLButtonElement);
    const move = (to: number) => {
        event.preventDefault();
        items[(to + items.length) % items.length]?.focus();
    };
    if (event.key === 'ArrowDown') move(index + 1);
    else if (event.key === 'ArrowUp') move(index < 0 ? items.length - 1 : index - 1);
    else if (event.key === 'Home') move(0);
    else if (event.key === 'End') move(items.length - 1);
    else if (event.key === 'Escape') {
        event.preventDefault();
        hide(true);
    } else if (event.key === 'Tab') hide(false);
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside, true));
</script>

<template>
    <div ref="root" class="relative">
        <button
            ref="trigger"
            type="button"
            class="flex min-h-tap min-w-tap items-center justify-center rounded-full text-primary-text disabled:opacity-50"
            :aria-label="label"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="open ? menuId : undefined"
            :disabled="disabled"
            data-test="menu"
            @click="toggle"
            @keydown.down.prevent="show"
        >
            <slot />
        </button>
        <div
            v-if="open"
            :id="menuId"
            role="menu"
            :aria-label="label"
            class="absolute z-10 mt-1 w-64 overflow-hidden rounded-xl bg-surface shadow-lg"
            :class="props.align === 'start' ? 'left-0' : 'right-0'"
            @keydown="onKeydown"
        >
            <button
                v-for="item in items"
                :key="item.key"
                ref="itemEls"
                type="button"
                role="menuitem"
                tabindex="-1"
                class="flex min-h-tap w-full flex-col justify-center px-4 py-2 text-left focus:bg-secondary-text/10 focus:outline-none disabled:text-secondary-text"
                :class="item.destructive ? 'text-tone-warning' : 'text-primary-text'"
                :disabled="item.disabled"
                :data-test="`item-${item.key}`"
                @click="choose(item)"
            >
                <span>{{ item.title }}</span>
                <span v-if="item.detail" class="text-sm text-secondary-text">{{ item.detail }}</span>
            </button>
        </div>
    </div>
</template>

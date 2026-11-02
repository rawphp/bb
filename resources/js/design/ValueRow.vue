<script lang="ts">
/** A numeric field in a ValueRow. */
export interface ValueField {
    id: string;
    /** Names the field and is its placeholder while empty. */
    title: string;
    unit?: string;
    /** `whole` opens the number keypad, `decimal` the decimal one (PWA-014). Defaults to `decimal`. */
    kind?: 'whole' | 'decimal';
    value: string;
}

/** A menu or swipe action on a ValueRow. */
export interface RowAction {
    id: string;
    title: string;
    isDestructive?: boolean;
}
</script>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

// Food rows and set rows share this layout: leading menu, label, right-aligned numeric fields,
// swipe actions (UI-008), ported from rp-clone's ValueRow.swift.
const props = withDefaults(
    defineProps<{
        label: string;
        detail?: string | null;
        fields: ValueField[];
        menuActions?: RowAction[];
        swipeActions?: RowAction[];
        /** On the workout screen: no swipe gesture, so nothing fires by accident (UI-020). */
        gym?: boolean;
        loading?: boolean;
        error?: string | null;
        /** The values are the user's own input waiting in the outbox (OFF-009). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    {
        detail: null,
        menuActions: () => [],
        swipeActions: () => [],
        gym: false,
        loading: false,
        error: null,
        pending: false,
        disabled: false,
    },
);

const emit = defineEmits<{
    /** Each keystroke, so the field shows what the user typed. */
    'update:field': [id: string, value: string];
    /** The user finished editing the field. */
    commit: [id: string, value: string];
    action: [id: string];
}>();

const uid = useId();
const inactive = computed(() => props.loading || props.disabled);

// Every swipe action is also in the menu, so keyboard, mouse, screen reader and gym users reach it.
const menuItems = computed(() => [
    ...props.menuActions,
    ...props.swipeActions.filter((swipe) => !props.menuActions.some((item) => item.id === swipe.id)),
]);

const describedBy = computed(
    () => [props.pending ? `${uid}-pending` : '', props.error ? `${uid}-error` : ''].filter(Boolean).join(' ') || undefined,
);

function fieldLabel(field: ValueField): string {
    return field.unit ? `${props.label} ${field.title}, ${field.unit}` : `${props.label} ${field.title}`;
}

function run(id: string): void {
    menuOpen.value = false;
    swipeOpen.value = false;
    emit('action', id);
}

// Menu (WAI-ARIA menu button): opening focuses the first item, arrows move, Escape closes.
const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement | null>(null);
const items: HTMLButtonElement[] = [];

async function toggleMenu(): Promise<void> {
    menuOpen.value = !menuOpen.value;
    if (menuOpen.value) {
        await nextTick();
        items[0]?.focus();
    }
}

function closeMenu(): void {
    menuOpen.value = false;
    menuButton.value?.focus();
}

function menuKey(event: KeyboardEvent, index: number): void {
    const count = menuItems.value.length;
    const step = { ArrowDown: 1, ArrowUp: -1 }[event.key];
    if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu();
    } else if (event.key === 'Tab') {
        menuOpen.value = false;
    } else if (step !== undefined) {
        event.preventDefault();
        items[(index + step + count) % count]?.focus();
    }
}

// Swipe left reveals the swipe actions; it never runs one by itself (no full swipe).
const swipeOpen = ref(false);
const drag = ref<number | null>(null);
const tray = ref<HTMLElement | null>(null);
const canSwipe = computed(() => !props.gym && !inactive.value && props.swipeActions.length > 0);
let start: { x: number; y: number; id: number; swiping: boolean } | null = null;

function trayWidth(): number {
    return tray.value?.offsetWidth ?? 0;
}

const shift = computed(() => {
    if (drag.value !== null) {
        return drag.value;
    }
    return swipeOpen.value ? -trayWidth() : 0;
});

function pointerDown(event: PointerEvent): void {
    if (canSwipe.value && event.pointerType !== 'mouse') {
        start = { x: event.clientX, y: event.clientY, id: event.pointerId, swiping: false };
    }
}

function pointerMove(event: PointerEvent): void {
    if (!start || event.pointerId !== start.id) {
        return;
    }
    const dx = event.clientX - start.x;
    if (!start.swiping) {
        if (Math.abs(dx) < 10 || Math.abs(dx) <= Math.abs(event.clientY - start.y)) {
            return;
        }
        start.swiping = true;
        (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
    }
    const base = swipeOpen.value ? -trayWidth() : 0;
    drag.value = Math.min(0, Math.max(-trayWidth(), base + dx));
}

function pointerUp(event: PointerEvent): void {
    if (!start || event.pointerId !== start.id) {
        return;
    }
    if (start.swiping) {
        const dx = event.clientX - start.x;
        swipeOpen.value = swipeOpen.value ? dx < 40 : dx < -40;
    } else if (swipeOpen.value) {
        swipeOpen.value = false;
    }
    start = null;
    drag.value = null;
}

function pointerCancel(): void {
    start = null;
    drag.value = null;
}

// A tap anywhere else closes the open menu and the revealed swipe actions.
const root = ref<HTMLElement | null>(null);

function outside(event: PointerEvent): void {
    if (!root.value?.contains(event.target as Node)) {
        menuOpen.value = false;
        swipeOpen.value = false;
    }
}

watch(
    () => menuOpen.value || swipeOpen.value,
    (open) => {
        if (open) {
            document.addEventListener('pointerdown', outside);
        } else {
            document.removeEventListener('pointerdown', outside);
        }
    },
);

watch(canSwipe, (can) => {
    if (!can) {
        swipeOpen.value = false;
    }
});

watch(inactive, (now) => {
    if (now) {
        menuOpen.value = false;
    }
});

onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));
</script>

<template>
    <div ref="root">
        <div class="relative overflow-hidden rounded-xl bg-surface">
            <div
                v-if="swipeActions.length > 0 && !gym"
                ref="tray"
                data-swipe-actions
                :inert="swipeOpen ? undefined : true"
                :aria-hidden="swipeOpen ? undefined : 'true'"
                class="absolute inset-y-0 right-0 flex"
            >
                <button
                    v-for="item in swipeActions"
                    :key="item.id"
                    type="button"
                    class="min-h-tap min-w-20 px-3 text-sm font-semibold select-none"
                    :class="item.isDestructive ? 'bg-tone-warning-bg text-tone-warning' : 'bg-accent text-on-accent'"
                    @click="run(item.id)"
                >
                    {{ item.title }}
                </button>
            </div>
            <div
                data-row
                :data-open="swipeOpen ? 'true' : undefined"
                :aria-busy="loading ? 'true' : undefined"
                class="relative flex min-h-tap items-center gap-2 bg-surface px-2"
                :class="[canSwipe ? 'touch-pan-y' : '', drag === null ? 'transition-transform' : '']"
                :style="shift ? { transform: `translateX(${shift}px)` } : undefined"
                @pointerdown="pointerDown"
                @pointermove="pointerMove"
                @pointerup="pointerUp"
                @pointercancel="pointerCancel"
            >
                <div v-if="menuItems.length > 0" class="relative shrink-0">
                    <button
                        ref="menuButton"
                        type="button"
                        :aria-label="`${label} menu`"
                        aria-haspopup="menu"
                        :aria-expanded="menuOpen ? 'true' : 'false'"
                        :aria-controls="menuOpen ? `${uid}-menu` : undefined"
                        :disabled="inactive"
                        class="flex min-h-tap min-w-tap items-center justify-center text-accent select-none"
                        :class="inactive ? 'opacity-60' : ''"
                        @click="toggleMenu"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75">
                            <circle cx="12" cy="12" r="9" />
                            <circle cx="8" cy="12" r="1" fill="currentColor" stroke="none" />
                            <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />
                            <circle cx="16" cy="12" r="1" fill="currentColor" stroke="none" />
                        </svg>
                    </button>
                    <div
                        v-if="menuOpen"
                        :id="`${uid}-menu`"
                        role="menu"
                        :aria-label="`${label} menu`"
                        class="absolute top-full left-0 z-10 flex min-w-48 flex-col rounded-xl border border-secondary-text/25 bg-surface py-1 shadow-lg"
                    >
                        <button
                            v-for="(item, index) in menuItems"
                            :key="item.id"
                            :ref="(el) => (items[index] = el as HTMLButtonElement)"
                            type="button"
                            role="menuitem"
                            tabindex="-1"
                            class="min-h-tap px-4 text-left text-base select-none"
                            :class="item.isDestructive ? 'text-tone-warning' : 'text-primary-text'"
                            @click="run(item.id)"
                            @keydown="menuKey($event, index)"
                        >
                            {{ item.title }}
                        </button>
                    </div>
                </div>
                <div class="flex min-w-0 grow flex-col gap-0.5 py-1.5">
                    <span class="text-base text-primary-text">{{ label }}</span>
                    <span v-if="detail" class="text-sm text-secondary-text">{{ detail }}</span>
                    <span v-if="pending" :id="`${uid}-pending`" class="text-xs text-secondary-text">Waiting to sync</span>
                </div>
                <label
                    v-for="field in fields"
                    :key="field.id"
                    class="flex min-h-tap shrink-0 items-center gap-0.5"
                    :class="[inactive ? 'opacity-60' : '', loading ? 'animate-pulse' : '']"
                >
                    <input
                        type="text"
                        :inputmode="field.kind === 'whole' ? 'numeric' : 'decimal'"
                        autocomplete="off"
                        :value="field.value"
                        :placeholder="field.title"
                        :aria-label="fieldLabel(field)"
                        :aria-describedby="describedBy"
                        :aria-invalid="error ? 'true' : undefined"
                        :disabled="inactive"
                        :size="Math.max(field.value.length, field.title.length, 3)"
                        class="min-h-tap min-w-14 bg-transparent text-right text-base text-primary-text tabular-nums placeholder:text-secondary-text"
                        @input="emit('update:field', field.id, ($event.target as HTMLInputElement).value)"
                        @change="emit('commit', field.id, ($event.target as HTMLInputElement).value)"
                    />
                    <span v-if="field.unit" aria-hidden="true" class="text-sm text-secondary-text">{{ field.unit }}</span>
                </label>
                <slot name="trailing" />
            </div>
        </div>
        <p v-if="error" :id="`${uid}-error`" role="alert" class="mt-2 text-sm text-tone-warning">{{ error }}</p>
    </div>
</template>

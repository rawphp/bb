<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { CustomFoodDeleteInput } from '@/contracts/custom_food_delete';
import type { CustomFoodsShowOutput } from '@/contracts/custom_foods_show';
import ValueRow, { type RowAction } from '@/design/ValueRow.vue';
import { toneClasses } from '@/design/tones';
import CustomFoodSheet from './CustomFoodSheet.vue';

// The Custom foods manager in More (FOOD-061), ported from rp-clone's CustomFoodsView.swift:
// the user's own foods, to edit and delete. `pending` marks the server's serving and
// calories as waiting to sync (OFF-008). `create` is set when the Pantry's "Can't find it?"
// link opened it (FOOD-062): the screen starts with a new food named after the search, and
// closing that sheet, saved or not, goes back to the Pantry.
type Food = CustomFoodsShowOutput['foods'][number];

const props = withDefaults(defineProps<{ payload?: CustomFoodsShowOutput; pending?: boolean; create?: string }>(), { pending: false, create: undefined });
const router = useRouter();

const { data, failure, load } = useRead<CustomFoodsShowOutput>('custom_foods_show', {}, 'Your custom foods could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

const ACTIONS: RowAction[] = [
    { id: 'edit', title: 'Edit' },
    { id: 'delete', title: 'Delete', isDestructive: true },
];

/** What the form is open for: a new food, a food to edit, or nothing. */
const form = ref<{ food: Food | null } | null>(props.create === undefined ? null : { food: null });
/** The open sheet is the one the Pantry asked for. */
const fromPantry = ref(props.create !== undefined);

function close(): void {
    form.value = null;
    if (fromPantry.value) {
        fromPantry.value = false;
        router.back();
    }
}

function act(food: Food, action: string): void {
    if (action === 'edit') form.value = { food };
    if (action === 'delete') void send('custom_food_delete', { food_id: food.food_id } satisfies CustomFoodDeleteInput, 'The food could not be deleted. Try again.');
}

</script>

<template>
    <main class="bb-screen-content space-y-4">
        <header class="flex items-center justify-between gap-2">
            <h1 class="text-3xl font-bold">Custom foods</h1>
            <button
                type="button"
                aria-label="New custom food"
                class="flex min-h-tap min-w-tap items-center justify-center rounded-full bg-surface text-primary-text shadow-sm active:opacity-70"
                @click="form = { food: null }"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
        </header>

        <template v-if="data">
            <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
            <ul v-if="data.foods.length" class="flex flex-col gap-2">
                <li v-for="food in data.foods" :key="food.food_id">
                    <ValueRow
                        :label="food.name"
                        :detail="food.brand ? `${food.brand}\n${food.detail}` : food.detail"
                        :fields="[]"
                        :swipe-actions="ACTIONS"
                        :pending="pending"
                        :disabled="busy"
                        @action="(action) => act(food, action)"
                    >
                        <template #trailing>
                            <button
                                type="button"
                                :aria-label="`Edit ${food.name}`"
                                :disabled="busy"
                                class="flex min-h-tap min-w-tap items-center justify-center text-secondary-text"
                                @click="form = { food }"
                            >
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m9 6 6 6-6 6" />
                                </svg>
                            </button>
                        </template>
                    </ValueRow>
                </li>
            </ul>
            <p v-else-if="data.empty_text" class="px-1 text-secondary-text">{{ data.empty_text }}</p>
        </template>
        <ReadState v-else :failure="failure" what="Your custom foods" @retry="load" />

        <CustomFoodSheet
            :open="form !== null"
            :food="form?.food ?? null"
            :name="fromPantry ? create : ''"
            :reload="load"
            @cancel="close"
            @saved="close"
        />
    </main>
</template>

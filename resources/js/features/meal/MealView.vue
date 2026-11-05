<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { MealCheckInInput } from '@/contracts/meal_check_in';
import type { MealCheckInUndoInput } from '@/contracts/meal_check_in_undo';
import type { MealCopyInput } from '@/contracts/meal_copy';
import type { MealDeleteInput } from '@/contracts/meal_delete';
import type { MealFoodAmountSetInput } from '@/contracts/meal_food_amount_set';
import type { MealFoodLockSetInput } from '@/contracts/meal_food_lock_set';
import type { MealFoodRemoveInput } from '@/contracts/meal_food_remove';
import type { MealFoodStepInput } from '@/contracts/meal_food_step';
import type { MealFoodsClearInput } from '@/contracts/meal_foods_clear';
import type { MealPlanningDoneInput } from '@/contracts/meal_planning_done';
import type { MealRebalanceInput } from '@/contracts/meal_rebalance';
import type { MealShowOutput } from '@/contracts/meal_show';
import type { SavedMealSaveInput } from '@/contracts/saved_meal_save';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import BottomSheet from '@/design/BottomSheet.vue';
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';
import CopySheet from '@/features/copy/CopySheet.vue';
import SavedMealPickerSheet from '@/features/saved-meals/SavedMealPickerSheet.vue';
import ScanView from '@/features/scan/ScanView.vue';
import MealAmountSheet from './MealAmountSheet.vue';
import MealFoodRowView from './MealFoodRowView.vue';
import MealRecipeRowView from './MealRecipeRowView.vue';
import MealSummaryCard from './MealSummaryCard.vue';

// The meal screen (MEAL-001 to MEAL-011, UI-021): food search and the camera on top, the
// meal's targets, the short-macro hints, its foods, and Rebalance at the bottom. The same
// screen opens from a Today card and from the Nutrition tab (NAV-002). Ported from rp-clone's
// MealView. The server decides every number, text and which menu items are on; this screen
// keeps only what is open and what was typed (VIEW-002). `pending` marks the server's
// numbers as waiting to sync (OFF-008).
type Entry = MealShowOutput['entries'][number];
type Repeat = NonNullable<MealShowOutput['empty']>['repeats'][number];
type MenuKey = MealShowOutput['menu'][number]['item'];

const props = withDefaults(defineProps<{ payload?: MealShowOutput; mealId?: string; action?: string; pending?: boolean }>(), { pending: false });

const router = useRouter();
const route = useRoute();
const mealId = props.mealId ?? props.payload?.meal_id ?? '';
const { data, failure, load } = useRead<MealShowOutput>('meal_show', { meal_id: mealId }, 'The meal could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);
// Each sheet's own write, so its refusal shows in the sheet and not on the screen.
const amountWrite = useWrite(load);
const nameWrite = useWrite(load);
// A deleted meal has nothing to read again: its write reads nothing, and the screen goes back.
const deleteWrite = useWrite(async () => {});

const editing = ref<Entry | null>(null);
const naming = ref(false);
const savedMealName = ref('');
const picking = ref(false);
const copying = ref(false);
const scanning = ref(false);
const confirmingDelete = ref(false);
const confirmingClear = ref(false);
// The new saved meal's id and the name it went with (CAP-019), kept until the server
// answers, so a retry of that save after no answer is the same meal and not a second one.
// Another name is another saved meal, with its own id.
let pendingSave: { id: string; name: string } | null = null;

// Edit macros/time has no screen yet (rp-clone shows a placeholder), and the manual check-in
// sheet is the Today screen's (#96). Until they land, the menu shows them off.
const NOT_YET: MenuKey[] = ['edit_macros_time', 'check_in_manual'];

const menuItems = computed<ActionMenuItem[]>(() =>
    (data.value?.menu ?? []).map((entry) => ({
        key: entry.item,
        title: entry.title,
        detail: NOT_YET.includes(entry.item) ? 'Not in the app yet' : null,
        disabled: !entry.is_enabled || NOT_YET.includes(entry.item),
        destructive: entry.item === 'delete_meal',
    })),
);

// The tick does what the menu offers: Check in meal, or Remove check-in.
const checkItem = computed(() => data.value?.menu.find((entry) => (entry.item === 'check_in_meal' || entry.item === 'remove_check_in') && entry.is_enabled)?.item);

function onMenu(key: string): void {
    const id = { meal_id: mealId };
    if (key === 'load_saved_meal') picking.value = true;
    else if (key === 'save_as_saved_meal') startNaming();
    else if (key === 'copy_foods') copying.value = true;
    else if (key === 'remove_foods') confirmingClear.value = true;
    else if (key === 'check_in_meal') void send('meal_check_in', { ...id } satisfies MealCheckInInput, 'The meal could not be checked in.');
    else if (key === 'remove_check_in') void send('meal_check_in_undo', { ...id } satisfies MealCheckInUndoInput, 'The check-in could not be removed.');
    else if (key === 'still_planning' || key === 'done_planning')
        void send('meal_planning_done', { ...id, done: key === 'done_planning' } satisfies MealPlanningDoneInput, 'The meal could not be changed.');
    else if (key === 'delete_meal') confirmingDelete.value = true;
}

// Today's meal menu hands these to the meal screen as /meals/:id?action=<item> (MEAL-070),
// as rp-clone opens the meal with the item to run. Once the meal has loaded the item runs
// as a menu tap would, if the server has it on, and the address drops it so a reload or a
// return does not run it again. An address must not destroy anything by itself: Remove foods
// asks first, as it does from the menu, and Delete is never run from here.
const ON_ARRIVAL: MenuKey[] = ['edit_macros_time', 'load_saved_meal', 'save_as_saved_meal', 'remove_foods'];

let arrival = props.action;
watch(
    data,
    (meal) => {
        const action = arrival;
        if (!meal || !action) return;
        arrival = undefined;
        const query = { ...route.query };
        delete query.action;
        void router.replace({ path: route.path, query });
        const item = menuItems.value.find((entry) => entry.key === action);
        if (!ON_ARRIVAL.includes(action as MenuKey) || !item || item.disabled) return;
        onMenu(action);
    },
    { immediate: true },
);

function check(): void {
    if (checkItem.value) onMenu(checkItem.value);
}

function lock(entry: Entry): void {
    const input: MealFoodLockSetInput = { meal_id: mealId, entry_id: entry.id, locked: !entry.is_locked };
    void send('meal_food_lock_set', { ...input }, 'The lock could not be changed.');
}

function step(entry: Entry, direction: MealFoodStepInput['direction']): void {
    const input: MealFoodStepInput = { meal_id: mealId, entry_id: entry.id, direction };
    void send('meal_food_step', { ...input }, 'The amount could not be changed.');
}

function remove(entry: Entry): void {
    const input: MealFoodRemoveInput = { meal_id: mealId, entry_id: entry.id };
    void send('meal_food_remove', { ...input }, 'The food could not be deleted.');
}

function edit(entry: Entry): void {
    amountWrite.error.value = null;
    editing.value = entry;
}

async function setAmount(value: number, unit: MealFoodAmountSetInput['unit']): Promise<void> {
    if (!editing.value) return;
    const input: MealFoodAmountSetInput = { meal_id: mealId, entry_id: editing.value.id, value, unit };
    if ((await amountWrite.send('meal_food_amount_set', { ...input }, 'The amount could not be changed.')) === 'done') editing.value = null;
}

// MEAL-061: the same meal from an earlier day, copied in as Copy foods does by default
// (MEAL-060): its foods re-fit to this meal's targets. The meal is empty, so nothing is replaced.
function repeat(earlier: Repeat): void {
    const input: MealCopyInput = { meal_id: earlier.meal_id, target_meal_ids: [mealId] };
    void send('meal_copy', { ...input }, 'The foods could not be copied.');
}

function rebalance(): void {
    void send('meal_rebalance', { meal_id: mealId } satisfies MealRebalanceInput, 'The meal could not be rebalanced.');
}

function startNaming(): void {
    nameWrite.error.value = null;
    savedMealName.value = data.value?.title ?? '';
    naming.value = true;
}

const trimmedName = computed(() => savedMealName.value.trim());

async function saveAsSavedMeal(): Promise<void> {
    if (trimmedName.value === '') return;
    if (pendingSave?.name !== trimmedName.value) pendingSave = { id: ulid(), name: trimmedName.value };
    const input: SavedMealSaveInput = { id: pendingSave.id, name: pendingSave.name, meal_id: mealId };
    const outcome = await nameWrite.send('saved_meal_save', { ...input }, 'The saved meal could not be saved.');
    if (outcome === 'done' || outcome === 'refused') pendingSave = null;
    if (outcome === 'done') naming.value = false;
}

async function deleteMeal(): Promise<void> {
    const outcome = await deleteWrite.send('meal_delete', { meal_id: mealId } satisfies MealDeleteInput, 'The meal could not be deleted.');
    confirmingDelete.value = false;
    if (outcome === 'done') router.back();
    else error.value = deleteWrite.error.value;
}

function clearFoods(): void {
    confirmingClear.value = false;
    void send('meal_foods_clear', { meal_id: mealId } satisfies MealFoodsClearInput, 'The foods could not be removed.');
}

function closeScanner(added: boolean): void {
    scanning.value = false;
    if (added) void load().catch(() => undefined);
}
</script>

<template>
    <main class="relative flex min-h-full flex-col">
        <header class="flex items-center gap-2 px-4 py-3">
            <span class="min-w-tap" aria-hidden="true" />
            <h1 class="flex-1 truncate text-center text-base font-semibold">{{ data?.title ?? 'Meal' }}</h1>
            <ActionMenu label="Meal menu" :items="menuItems" :disabled="!data || busy" @select="onMenu">
                <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" />
                    <circle cx="7.5" cy="12" r="1" fill="currentColor" />
                    <circle cx="12" cy="12" r="1" fill="currentColor" />
                    <circle cx="16.5" cy="12" r="1" fill="currentColor" />
                </svg>
            </ActionMenu>
        </header>

        <div v-if="error" role="alert" class="mx-4 mb-3 flex items-start gap-2 rounded-2xl p-3" :class="toneClasses.warning" data-test="error">
            <p class="flex-1 text-sm"><span class="font-semibold">Something went wrong.</span> {{ error }}</p>
            <button type="button" class="min-h-tap px-2 font-semibold" @click="error = null">OK</button>
        </div>

        <div v-if="data" class="flex flex-1 flex-col gap-2.5 px-4 pb-4" :aria-busy="busy || undefined">
            <!-- UI-021: food search and the camera stay on top. Typing happens in the Pantry. -->
            <div class="flex gap-2">
                <RouterLink
                    :to="{ path: '/nutrition/pantry', query: { meal: mealId } }"
                    class="flex min-h-tap flex-1 items-center gap-2 rounded-xl bg-surface px-3 text-secondary-text"
                    aria-label="Search foods"
                    data-test="search"
                >
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path d="m20 20-3.5-3.5" />
                    </svg>
                    Search foods
                </RouterLink>
                <button type="button" class="flex min-h-tap min-w-tap items-center justify-center rounded-xl bg-surface text-accent" aria-label="Scan a food" data-test="scan" @click="scanning = true">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 8h3l2-3h6l2 3h3v11H4z" />
                        <circle cx="12" cy="13" r="3.5" />
                    </svg>
                </button>
            </div>

            <MealSummaryCard :meal="data" :checkable="checkItem !== undefined" :busy="busy" :pending="pending" @check="check" />

            <!-- MEAL-007: which macro is short, and the Pantry on the group to add from. -->
            <RouterLink
                v-for="hint in data.hints"
                :key="hint.group"
                :to="{ path: '/nutrition/pantry', query: { meal: mealId, group: hint.group } }"
                class="flex min-h-tap items-center gap-2 rounded-xl p-3 text-sm font-semibold"
                :class="toneClasses.warning"
                data-test="hint"
            >
                <svg viewBox="0 0 24 24" class="size-5 shrink-0" fill="currentColor" aria-hidden="true"><path :d="toneIcons.warning" /></svg>
                <span class="flex-1">{{ hint.text }}</span>
                <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 5 7 7-7 7" /></svg>
            </RouterLink>

            <!-- MEAL-046. -->
            <section v-if="data.empty" class="flex flex-col gap-1.5 py-1" data-test="empty">
                <h2 class="font-semibold text-primary-text">{{ data.empty.title }}</h2>
                <p class="text-sm text-secondary-text">{{ data.empty.note }}</p>
                <p v-if="!data.empty.repeats.length" class="text-sm text-secondary-text">Search above to add foods, and they scale to hit this meal's targets.</p>
            </section>

            <!-- MEAL-061: one tap has an earlier day's meal again. -->
            <section v-if="data.empty?.repeats.length" class="flex flex-col gap-2 pt-1" data-test="repeats">
                <h2 class="px-1 text-sm font-semibold text-secondary-text">Repeat a recent {{ data.title }}</h2>
                <ul class="overflow-hidden rounded-2xl bg-surface">
                    <li v-for="earlier in data.empty.repeats" :key="earlier.meal_id" class="[&+&]:border-t [&+&]:border-secondary-text/15">
                        <button
                            type="button"
                            class="flex min-h-tap w-full items-center gap-3 px-4 py-3 text-left active:opacity-70 disabled:opacity-40"
                            :disabled="busy"
                            aria-description="Copies these foods into this meal"
                            data-test="repeat"
                            @click="repeat(earlier)"
                        >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="font-semibold text-primary-text">{{ earlier.title }}</span>
                                <span class="line-clamp-2 text-sm text-secondary-text">{{ earlier.foods_text }}</span>
                            </span>
                            <span class="shrink-0 rounded-full bg-accent/15 px-3.5 py-1.5 text-sm font-semibold text-accent" aria-hidden="true">Use</span>
                        </button>
                    </li>
                </ul>
            </section>

            <ul v-if="data.entries.length" class="flex flex-col gap-2.5" aria-label="Foods">
                <li v-for="entry in data.entries" :key="entry.id">
                    <MealRecipeRowView
                        v-if="entry.kind === 'recipe'"
                        :entry="entry"
                        :busy="busy"
                        :pending="pending"
                        @lock="lock(entry)"
                        @minus="step(entry, 'down')"
                        @plus="step(entry, 'up')"
                        @delete="remove(entry)"
                    />
                    <MealFoodRowView
                        v-else
                        :entry="entry"
                        :busy="busy"
                        :pending="pending"
                        @lock="lock(entry)"
                        @minus="step(entry, 'down')"
                        @plus="step(entry, 'up')"
                        @edit="edit(entry)"
                        @delete="remove(entry)"
                    />
                </li>
            </ul>
        </div>
        <div v-else class="px-4">
            <ReadState :failure="failure" what="The meal" @retry="load" />
        </div>

        <!-- MEAL-006. -->
        <div v-if="data" class="sticky bottom-0 bg-background/90 px-4 py-2 backdrop-blur">
            <button
                type="button"
                class="flex min-h-tap w-full items-center justify-center gap-2 rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40"
                :disabled="data.entries.length === 0 || busy"
                data-test="rebalance"
                @click="rebalance"
            >
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 4v2M6 20h12l-2-12H8z" />
                </svg>
                Rebalance
            </button>
        </div>

        <MealAmountSheet :entry="editing" :busy="amountWrite.busy.value" :error="amountWrite.error.value" @cancel="editing = null" @save="setAmount" />

        <BottomSheet
            :open="naming"
            title="Save as saved meal"
            :heights="['half']"
            :save-disabled="trimmedName === '' || nameWrite.busy.value"
            :cancel-disabled="nameWrite.busy.value"
            :dismissible="!nameWrite.busy.value"
            :error="nameWrite.error.value"
            @cancel="naming = false"
            @save="saveAsSavedMeal"
        >
            <form class="p-4" @submit.prevent="saveAsSavedMeal">
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm text-secondary-text">Name</span>
                    <input
                        v-model="savedMealName"
                        name="saved-meal-name"
                        type="text"
                        autocomplete="off"
                        :disabled="nameWrite.busy.value"
                        class="min-h-tap w-full rounded-xl bg-background px-3.5 text-base text-primary-text"
                    />
                </label>
            </form>
        </BottomSheet>

        <BottomSheet
            :open="confirmingDelete"
            :title="`Delete ${data?.title ?? 'meal'}?`"
            :heights="['half']"
            :save-title="null"
            :cancel-disabled="deleteWrite.busy.value"
            :dismissible="!deleteWrite.busy.value"
            @cancel="confirmingDelete = false"
        >
            <div class="flex flex-col gap-3 p-4">
                <p class="text-secondary-text">Its foods and check-in go with it.</p>
                <button
                    type="button"
                    class="min-h-tap rounded-2xl font-semibold disabled:opacity-40"
                    :class="toneClasses.warning"
                    :disabled="deleteWrite.busy.value"
                    data-test="confirm-delete"
                    @click="deleteMeal"
                >
                    Delete meal
                </button>
            </div>
        </BottomSheet>

        <BottomSheet :open="confirmingClear" :title="`Remove foods from ${data?.title ?? 'meal'}?`" :heights="['half']" :save-title="null" @cancel="confirmingClear = false">
            <div class="flex flex-col gap-3 p-4">
                <p class="text-secondary-text">Every food and recipe in it goes.</p>
                <button type="button" class="min-h-tap rounded-2xl font-semibold disabled:opacity-40" :class="toneClasses.warning" :disabled="busy" data-test="confirm-clear" @click="clearFoods">
                    Remove foods
                </button>
            </div>
        </BottomSheet>

        <SavedMealPickerSheet :open="picking" :meal-id="mealId" :reload="load" @cancel="picking = false" @loaded="picking = false" />
        <CopySheet v-if="copying" :meal-id="mealId" @close="copying = false" />
        <ScanView v-if="scanning" :meal-id="mealId" @close="closeScanner" />
    </main>
</template>

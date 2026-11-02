<script setup lang="ts">
import { ref, watch } from 'vue';
import type { CopyShowOutput } from '@/contracts/copy_show';
import BottomSheet from '@/design/BottomSheet.vue';

// The three advanced options of a copy (MEAL-060). Done hands back the changes; Cancel drops them.
type Options = CopyShowOutput['options'];

const props = defineProps<{ open: boolean; options: Options }>();
const emit = defineEmits<{ done: [options: Options]; cancel: [] }>();

const draft = ref<Options>({ ...props.options });
watch(
    () => props.open,
    (open) => {
        if (open) draft.value = { ...props.options };
    },
);

const rows: { key: keyof Options; title: string; detail: string }[] = [
    { key: 'update_target_macros', title: 'Update destination target macros', detail: 'The copied foods re-fit to each destination meal. Locked foods keep their amounts.' },
    { key: 'update_meal_times', title: 'Update destination meal times', detail: "Each destination meal takes the source meal's time. Intra-workout shakes keep their own." },
    { key: 'copy_locked_state', title: 'Copy locked state', detail: 'The copies keep the locks of the source foods. Off, they arrive unlocked.' },
];
</script>

<template>
    <BottomSheet :open="open" title="Advanced options" save-title="Done" :heights="['half']" @cancel="emit('cancel')" @save="emit('done', { ...draft })">
        <div class="space-y-5 p-4">
            <div v-for="row in rows" :key="row.key" class="space-y-1">
                <label class="flex min-h-tap items-center justify-between gap-3">
                    <span class="font-semibold">{{ row.title }}</span>
                    <input v-model="draft[row.key]" type="checkbox" role="switch" class="peer sr-only" />
                    <span
                        aria-hidden="true"
                        class="relative h-8 w-13 shrink-0 rounded-full bg-tone-neutral-bg transition-colors peer-checked:bg-accent peer-focus-visible:ring-2 peer-focus-visible:ring-accent after:absolute after:top-0.5 after:left-0.5 after:size-7 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"
                    />
                </label>
                <p class="text-sm text-secondary-text">{{ row.detail }}</p>
            </div>
        </div>
    </BottomSheet>
</template>

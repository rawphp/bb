<script setup lang="ts">
import { computed } from 'vue';
import type { TodayShowOutput } from '@/contracts/today_show';
import ItemCard, { type ItemCardStatus } from '@/design/ItemCard.vue';

// SCH-004: the scheduled weigh-in with its weight, or a button to add one. Ported from
// rp-clone's TodayWeighInRow.
const props = defineProps<{ weighIn: TodayShowOutput['weigh_ins'][number] }>();

const emit = defineEmits<{ add: [] }>();

const status = computed<ItemCardStatus>(() =>
    props.weighIn.weight_text ? { kind: 'value', text: props.weighIn.weight_text } : { kind: 'button', title: 'Add weigh-in' },
);
</script>

<template>
    <ItemCard data-test="weigh-in" title="Weigh-in" :time="weighIn.time_text" :status="status" @action="emit('add')">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                <path d="M5 4h14l2 16H3z" />
                <path d="M9 9a3 3 0 0 1 6 0M12 9l1.5-2" stroke-linecap="round" />
            </svg>
        </template>
    </ItemCard>
</template>

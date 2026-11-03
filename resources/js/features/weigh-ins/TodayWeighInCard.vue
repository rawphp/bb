<script setup lang="ts">
import { computed } from 'vue';
import type { TodayShowOutput } from '@/contracts/today_show';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import ItemCard, { type ItemCardStatus } from '@/design/ItemCard.vue';

// Today's weigh-in, or a button to add one, with the trend weight (WT-002, WT-011). Ported
// from rp-clone's TodayWeighInCard.swift. It shows today_show's weigh-in and weigh_ins_show's
// trend as the server words them; the screen that mounts it opens the weigh-in sheet on `add`.
const props = withDefaults(
    defineProps<{ weighIn: TodayShowOutput['weigh_ins'][number]; trend: WeighInsShowOutput['trend']; pending?: boolean }>(),
    { pending: false },
);

const emit = defineEmits<{ add: [] }>();

const status = computed<ItemCardStatus>(() =>
    props.weighIn.weight_text ? { kind: 'value', text: props.weighIn.weight_text } : { kind: 'button', title: 'Add weigh-in' },
);
</script>

<template>
    <ItemCard title="Weigh-in" :status="status" :pending="pending" @action="emit('add')">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                <path d="M5 4h14l2 16H3z" />
                <path d="M9 9a3 3 0 0 1 6 0M12 9l1.5-2" stroke-linecap="round" />
            </svg>
        </template>
        <p class="text-sm text-secondary-text">{{ trend.label }} {{ trend.value }}</p>
    </ItemCard>
</template>

<script setup lang="ts">
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ItemCard from '@/design/ItemCard.vue';

// "Weekly review available" (ANA-004).
defineProps<{ card: NonNullable<ProgressShowOutput['review_card']> }>();
const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <ItemCard data-review-card :title="card.title" :status="{ kind: 'button', title: card.action_title }" @action="emit('open')">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 6.5l1.5 1.5L8 5.5M4 12.5l1.5 1.5L8 11.5M4 18.5l1.5 1.5L8 17.5M11 7h9M11 13h9M11 19h9" />
            </svg>
        </template>
    </ItemCard>
</template>

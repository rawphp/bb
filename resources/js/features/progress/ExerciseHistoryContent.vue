<script setup lang="ts">
import type { ExerciseHistoryShowOutput } from '@/contracts/exercise_history_show';
import ItemCard from '@/design/ItemCard.vue';
import OfflineNotice from '@/design/OfflineNotice.vue';
import Skeleton from '@/design/Skeleton.vue';
import StatusPill from '@/design/StatusPill.vue';

// The sessions of one exercise read so far with their sets, the best e1RM of each marked (ANA-009).
// No title, so the exercise screen can embed it as its History tab (EX-002). When older sessions
// exist, a button under the last one asks for them (`more`); the parent reads and appends them.
defineProps<{
    payload?: ExerciseHistoryShowOutput;
    error?: string | null;
    offline?: boolean;
    pending?: boolean;
    loadingMore?: boolean;
    moreError?: string | null;
}>();
defineEmits<{ more: [] }>();
</script>

<template>
    <div class="flex flex-col gap-3">
        <OfflineNotice v-if="offline" needs="Exercise history needs a connection." />
        <p v-else-if="error" role="alert" class="rounded-2xl bg-surface p-4 text-tone-warning">{{ error }}</p>
        <Skeleton v-else-if="!payload" shape="card" :count="3" label="Loading history" />
        <template v-else>
            <p v-if="!payload.sessions.length" class="text-sm text-secondary-text">No sessions logged yet.</p>
            <ItemCard v-for="session in payload.sessions" :key="session.id" data-session :title="session.date_label" :pending="pending">
                <template #icon>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
                        <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                        <path d="M3.5 9.5h17M8 3v4M16 3v4M8 13h.01M12 13h.01M16 13h.01M8 16.5h.01M12 16.5h.01" />
                    </svg>
                </template>
                <div class="flex flex-col gap-1.5">
                    <div
                        v-for="set in session.sets"
                        :key="set.id"
                        data-set
                        class="flex min-h-8 items-center justify-between gap-2"
                        :aria-description="set.is_best ? 'Best estimated one rep max' : undefined"
                    >
                        <span class="text-lg tabular-nums">{{ set.label }}</span>
                        <StatusPill v-if="set.is_best" :text="payload.best.text" :tone="payload.best.tone" />
                    </div>
                    <p class="text-sm text-secondary-text">{{ session.best_label }}</p>
                </div>
            </ItemCard>
            <template v-if="payload.next_before">
                <button
                    type="button"
                    data-more
                    class="min-h-tap w-full rounded-2xl bg-surface font-semibold text-accent active:opacity-70 disabled:opacity-50"
                    :disabled="loadingMore"
                    @click="$emit('more')"
                >
                    Show older sessions
                </button>
                <p v-if="moreError" role="alert" class="text-sm text-tone-warning">{{ moreError }}</p>
            </template>
        </template>
    </div>
</template>

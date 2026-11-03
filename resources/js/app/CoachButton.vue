<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { read } from '@/app/call';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import CoachChat from '@/features/coach/CoachChat.vue';

// The Coach button above the tab bar (NAV-001), on every tab while the instance has the
// agent (AGT-002) and the user has their Coach on (AGT-020). It opens the chat as a
// full-height sheet. Both flags are the server's, from agent_status_show, read again on
// each screen, so turning the Coach off in Settings hides it. AppShell finds this file
// and puts it in its slot.
const route = useRoute();
const status = ref<AgentStatusShowOutput | null>(null);
const open = ref(false);

const shown = computed(() => status.value?.enabled === true && status.value.on === true);

function load(): void {
    read<AgentStatusShowOutput>(
        'agent_status_show',
        {},
        (fresh) => (status.value = fresh),
        () => (status.value = null),
    ).then(
        (answer) => (status.value = answer),
        () => {
            // Offline with nothing cached, or signed out: no Coach button.
        },
    );
}

onMounted(load);
watch(() => route.fullPath, load);
</script>

<template>
    <div v-if="shown" class="px-3 pb-2">
        <button
            type="button"
            data-test="coach"
            aria-label="Coach"
            aria-description="Opens the chat with the Coach"
            class="flex size-14 items-center justify-center rounded-full bg-accent text-on-accent shadow-lg active:opacity-80"
            @click="open = true"
        >
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z" />
            </svg>
        </button>
        <CoachChat :open="open" @close="open = false" />
    </div>
</template>

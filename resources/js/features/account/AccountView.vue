<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { CapabilityError, read } from '@/app/call';
import type { AccountShowOutput } from '@/contracts/account_show';

// Placeholder screen for account_show. A screen takes its payload as a prop so
// /dev/screens/account_show can render it from a fixture; otherwise it reads.
const props = defineProps<{ payload?: AccountShowOutput }>();

const account = ref<AccountShowOutput | undefined>(props.payload);
const failure = ref<'signed-out' | 'error' | null>(null);

onMounted(async () => {
    if (account.value) return;
    try {
        account.value = await read<AccountShowOutput>('account_show');
    } catch (error) {
        failure.value = error instanceof CapabilityError && error.status === 401 ? 'signed-out' : 'error';
    }
});
</script>

<template>
    <main class="p-6">
        <h1 class="text-lg font-semibold">Account</h1>
        <dl v-if="account" class="mt-4 space-y-2">
            <div>
                <dt class="text-sm opacity-70">Name</dt>
                <dd>{{ account.name }}</dd>
            </div>
            <div>
                <dt class="text-sm opacity-70">Email</dt>
                <dd>{{ account.email }}</dd>
            </div>
        </dl>
        <p v-else-if="failure === 'signed-out'" class="mt-4">You are signed out.</p>
        <p v-else-if="failure === 'error'" class="mt-4">Your account could not be loaded.</p>
        <p v-else class="mt-4" aria-busy="true">Loading…</p>
    </main>
</template>

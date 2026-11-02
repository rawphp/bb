<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { AccountShowOutput } from '@/contracts/account_show';

// Placeholder screen for account_show. A screen takes its payload as a prop so
// /dev/screens/account_show can render it from a fixture; otherwise it reads.
const props = defineProps<{ payload?: AccountShowOutput }>();

const { data: account, failure, load } = useRead<AccountShowOutput>('account_show', {}, 'Your account could not be loaded.', props.payload);
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
        <div v-else class="mt-4"><ReadState :failure="failure" what="Your account" @retry="load" /></div>
    </main>
</template>

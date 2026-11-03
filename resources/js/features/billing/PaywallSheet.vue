<script setup lang="ts">
import { ref } from 'vue';
import type { BillingShowOutput } from '@/contracts/billing_show';
import BottomSheet from '@/design/BottomSheet.vue';
import PaywallView from './PaywallView.vue';

// The paywall as a sheet, ported from rp-clone's PaywallSheet (PaywallView.swift). It
// opens at half height over the screen, so what the user is paying for stays in view
// above it (SUB-004); a drag up shows it all. It cannot be closed while Checkout starts.
// Given billing_show's answer (the lapsed banner has it) it reads nothing; otherwise the
// paywall reads it when the sheet opens.
defineProps<{ open: boolean; payload?: BillingShowOutput }>();
const emit = defineEmits<{ close: [] }>();

const busy = ref(false);
</script>

<template>
    <BottomSheet
        :open="open"
        title="Subscribe"
        cancel-title="Not now"
        :save-title="null"
        :heights="['half', 'full']"
        :cancel-disabled="busy"
        :dismissible="!busy"
        @cancel="emit('close')"
    >
        <PaywallView :payload="payload" @busy="busy = $event" />
    </BottomSheet>
</template>

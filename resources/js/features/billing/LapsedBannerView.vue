<script setup lang="ts">
import { ref } from 'vue';
import { useRead } from '@/app/useRead';
import type { BillingShowOutput } from '@/contracts/billing_show';
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';
import PaywallSheet from './PaywallSheet.vue';

// The banner over every tab once a subscription has lapsed (SUB-010), ported from
// rp-clone's LapsedBannerView.swift. The data stays; the server refuses the writes
// (SUB-022). billing_show sends the banner's words only then. With billing off there is
// no billing_show (SUB-020), so a failed read, like loading, shows nothing: a banner
// never puts a skeleton or an error over a tab. Resubscribe opens the paywall with
// the plans this read already has.
const props = defineProps<{ payload?: BillingShowOutput }>();

const { data: billing } = useRead<BillingShowOutput>('billing_show', {}, 'The subscription could not be loaded.', props.payload);
const paying = ref(false);
</script>

<template>
    <div v-if="billing?.lapsed">
        <div class="flex items-center gap-3 border-b border-secondary-text/25 px-4 py-1.5" :class="toneClasses.warning" data-test="lapsed">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0" fill="currentColor">
                <path :d="toneIcons.warning" />
            </svg>
            <p class="grow text-sm">{{ billing.lapsed.text }}</p>
            <button
                type="button"
                class="min-h-tap shrink-0 rounded-full bg-accent px-4 text-sm font-semibold text-on-accent active:opacity-70"
                data-test="resubscribe"
                @click="paying = true"
            >
                {{ billing.lapsed.action_label }}
            </button>
        </div>
        <PaywallSheet :open="paying" :payload="billing" @close="paying = false" />
    </div>
</template>

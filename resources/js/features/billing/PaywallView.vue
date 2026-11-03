<script setup lang="ts">
import { computed, ref } from 'vue';
import { writeNow } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { BillingCheckoutStartInput, BillingCheckoutStartOutput } from '@/contracts/billing_checkout_start';
import type { BillingShowOutput } from '@/contracts/billing_show';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import { toneClasses } from '@/design/tones';

// The paywall (SUB-002 to SUB-005, SUB-009), ported from rp-clone's PaywallView.swift.
// Billing on only: billing_show exists only then (SUB-020). The server sends the plans
// on sale, their prices, the plan to select first, the button's words (the 7-day trial
// or Subscribe, SUB-003) and the terms. The button starts Stripe Checkout and goes
// there; promotion codes are entered in Checkout (SUB-009), and a Stripe subscription
// belongs to the account, so rp-clone's Restore purchases and Redeem code are gone
// (SUB-008). PaywallSheet shows it over the screen it unlocks (SUB-004).
type Plan = BillingShowOutput['plans'][number]['plan'];

const props = defineProps<{ payload?: BillingShowOutput }>();
const emit = defineEmits<{ busy: [busy: boolean] }>();

const { data: billing, failure, load } = useRead<BillingShowOutput>('billing_show', {}, 'The plans could not be loaded.', props.payload);
const picked = ref<Plan | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);

const choices = computed<Choice<Plan>[]>(() =>
    (billing.value?.plans ?? []).map((plan) => ({ id: plan.plan, title: plan.title, detail: plan.detail ? `${plan.price_text} · ${plan.detail}` : plan.price_text })),
);
// The user's pick, else the server's first choice while it is on sale.
const selected = computed<Plan | null>(() => {
    const plan = picked.value ?? billing.value?.default_plan ?? null;
    return choices.value.some((choice) => choice.id === plan) ? plan : null;
});

function setBusy(now: boolean): void {
    busy.value = now;
    emit('busy', now);
}

async function checkout(): Promise<void> {
    if (busy.value || selected.value === null) return;
    setBusy(true);
    error.value = null;
    try {
        const started = await writeNow<BillingCheckoutStartOutput>('billing_checkout_start', { plan: selected.value } satisfies BillingCheckoutStartInput);
        // Stays busy: the page is leaving for Checkout.
        window.location.assign(started.url);
    } catch (failed) {
        error.value = writeFailureMessage(failed, 'Checkout could not be started. Try again.');
        setBusy(false);
    }
}

function retry(): void {
    void load().catch(() => undefined);
}
</script>

<template>
    <section class="space-y-3 p-4">
        <template v-if="billing">
            <div class="space-y-1">
                <h1 class="text-xl font-bold">Unlock your plan</h1>
                <p class="text-sm text-secondary-text">One subscription unlocks every feature.</p>
            </div>

            <template v-if="choices.length > 0">
                <ChoiceList label="Plans" :choices="choices" :model-value="selected" :disabled="busy" @update:model-value="picked = $event" />
                <p v-if="error" class="rounded-2xl p-4" :class="toneClasses.warning" role="alert">{{ error }}</p>
                <button
                    type="button"
                    class="min-h-tap w-full rounded-full bg-accent px-4 py-3 font-semibold text-on-accent active:opacity-70 disabled:opacity-60"
                    data-test="checkout"
                    :disabled="busy || selected === null"
                    :aria-busy="busy"
                    @click="checkout"
                >
                    {{ billing.primary_label }}
                </button>
                <p class="text-xs text-secondary-text" data-test="terms">{{ billing.terms_text }}</p>
            </template>
            <!-- Stripe gave no prices just now: nothing to pay for yet. -->
            <button
                v-else
                type="button"
                class="min-h-tap w-full rounded-xl border border-secondary-text/25 px-4 text-accent active:opacity-70"
                data-test="retry-plans"
                @click="retry"
            >
                Try again
            </button>
        </template>

        <ReadState v-else :failure="failure" what="The plans" @retry="retry" />
    </section>
</template>

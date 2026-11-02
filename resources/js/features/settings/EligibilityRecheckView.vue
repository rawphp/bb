<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { write } from '@/app/call';
import { useNavigation, type Navigation } from '@/app/navigation';
import { failureMessage } from '@/app/errors';
import { useRead } from '@/app/useRead';
import type { EligibilityRecheckInput, EligibilityRecheckOutput } from '@/contracts/eligibility_recheck';
import type { EligibilityRecheckShowOutput } from '@/contracts/eligibility_recheck_show';
import ReadState from './ReadState.vue';
import { TONE_CLASS } from './tones';

// Settings → Re-check eligibility (SAFE-017). The screening again; the server decides
// the outcome and whether the nutrition steps come next.
const props = defineProps<{ payload?: EligibilityRecheckShowOutput }>();

type Item = EligibilityRecheckInput['answers'][number]['item'];

const router = useRouter();
const nav = shellNavigation();
const { data: screening, failure, load } = useRead<EligibilityRecheckShowOutput>('eligibility_recheck_show', props.payload);
const ticked = ref<Partial<Record<Item, boolean>>>({});
// Boxes the user has changed. A background refresh (OFF-003) never overrides them.
const touched = new Set<Item>();
const saving = ref(false);
const error = ref<string | null>(null);
const outcome = ref<EligibilityRecheckOutput | null>(null);

// The boxes start as the server last saw them, and follow a refresh until the user ticks one.
watch(
    screening,
    (show) => {
        for (const q of show?.questions ?? []) if (q.measured || !touched.has(q.item)) ticked.value[q.item] = q.applies;
    },
    { immediate: true },
);

async function check(): Promise<void> {
    if (!screening.value || saving.value) return;
    saving.value = true;
    error.value = null;
    const input: EligibilityRecheckInput = {
        answers: screening.value.questions.map((q) => ({ item: q.item, applies: ticked.value[q.item] ?? q.applies })),
    };
    try {
        outcome.value = await write<EligibilityRecheckOutput>('eligibility_recheck', { ...input });
    } catch (failed) {
        error.value = failureMessage(failed, 'Your answers could not be checked. Try again.');
    } finally {
        saving.value = false;
    }
}

// The tab stack's back, which stays in the app. Opened on its own (a deep link, a
// reload, /dev/screens) there is no screen under this one, so Done goes to More.
function finish(): void {
    if (outcome.value?.next === 'onboarding') void router.push('/onboarding');
    else if (nav && nav.state.stacks[nav.state.active].length > 1) nav.back();
    else void router.replace('/more');
}

function shellNavigation(): Navigation | undefined {
    try {
        return useNavigation();
    } catch {
        // A router not made by makeRouter() (dev screens, tests) has no tab stacks.
        return undefined;
    }
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Re-check eligibility</h1>

        <template v-if="outcome">
            <p class="rounded-2xl bg-surface p-4 whitespace-pre-line">{{ outcome.message }}</p>
            <button
                type="button"
                class="min-h-tap w-full rounded-2xl bg-accent font-semibold text-on-accent active:opacity-70"
                data-test="continue"
                @click="finish"
            >
                {{ outcome.next === 'onboarding' ? 'Continue' : 'Done' }}
            </button>
        </template>

        <template v-else-if="screening">
            <p class="flex items-center gap-2 text-sm">
                <span class="text-secondary-text">Now:</span>
                <span class="rounded-full px-2.5 py-0.5 font-medium" :class="TONE_CLASS[screening.mode.tone]">{{ screening.mode.text }}</span>
            </p>
            <ul v-if="screening.reasons.length" class="space-y-2 rounded-2xl p-4" :class="TONE_CLASS.warning">
                <li v-for="reason in screening.reasons" :key="reason.text">{{ reason.text }}</li>
            </ul>
            <p class="rounded-2xl p-4" :class="TONE_CLASS.warning">Get medical clearance from your doctor before starting any diet.</p>

            <fieldset class="space-y-2">
                <legend class="mb-2 font-semibold">Tick anything that applies to you.</legend>
                <label
                    v-for="question in screening.questions"
                    :key="question.item"
                    class="flex min-h-tap items-center gap-3 rounded-xl bg-surface px-4 py-2 active:opacity-70"
                    :class="{ 'opacity-60': question.measured }"
                >
                    <input
                        v-model="ticked[question.item]"
                        type="checkbox"
                        @change="touched.add(question.item)"
                        class="size-5 accent-accent"
                        :disabled="question.measured || saving"
                        :aria-describedby="question.measured ? `measured-${question.item}` : undefined"
                    />
                    <span class="flex-1">
                        {{ question.label }}
                        <span v-if="question.measured" :id="`measured-${question.item}`" class="block text-sm text-secondary-text">From your profile</span>
                    </span>
                </label>
            </fieldset>

            <p class="rounded-2xl bg-surface p-4 text-sm">This app does not support medical diets. If you have a metabolic condition, consult a physician first.</p>
            <p v-if="error" class="rounded-2xl p-4" :class="TONE_CLASS.warning" role="alert">{{ error }}</p>
            <button
                type="button"
                class="min-h-tap w-full rounded-2xl bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
                data-test="check"
                :disabled="saving"
                :aria-busy="saving"
                @click="check"
            >
                {{ saving ? 'Checking…' : 'Check' }}
            </button>
        </template>

        <ReadState v-else :failure="failure" what="Your screening" @retry="load" />
    </main>
</template>

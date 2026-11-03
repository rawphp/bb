<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { ProfileUpdateInput } from '@/contracts/profile_update';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import { toneClasses } from '@/design/tones';
import SwitchRow from './SwitchRow.vue';

// Settings → Profile (SET-004): name, sex, date of birth, height, body-fat bucket, the
// GLP-1 answer and training experience. The form starts from what settings_show holds
// and Save sends it whole (profile_update); the server recalculates the targets from
// the next day and says what it did in `notice`. Save stays on, so a save whose targets
// did not update can be sent again (the notice asks for that). The form follows each new
// answer (a refresh of the cached copy, say) until the user changes something.
const props = defineProps<{ payload?: SettingsShowOutput }>();

const { data: settings, failure, load } = useRead<SettingsShowOutput>('settings_show', {}, 'Your profile could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

type Sex = ProfileUpdateInput['sex'];
type Draft = {
    unit: SettingsShowOutput['profile']['height']['unit'];
    name: string;
    sex: Sex;
    birth_date: string;
    cm: string;
    feet: string;
    inches: string;
    body_fat_bucket: ProfileUpdateInput['body_fat_bucket'];
    uses_glp1: boolean;
    experience: ProfileUpdateInput['experience'];
};

const draft = ref<Draft | null>(null);
// The draft as last filled from the server, to tell whether the user has changed it.
let seeded = '';

function seed(from: SettingsShowOutput): void {
    const profile = from.profile;
    const text = (value: number | null | undefined) => (value === null || value === undefined ? '' : String(value));
    draft.value = {
        unit: profile.height.unit,
        name: profile.name,
        sex: profile.sex.value as Sex,
        birth_date: profile.birth_date,
        cm: text(profile.height.cm),
        feet: text(profile.height.feet),
        inches: text(profile.height.inches),
        body_fat_bucket: profile.body_fat.value,
        uses_glp1: profile.uses_glp1,
        experience: profile.experience.value as ProfileUpdateInput['experience'],
    };
    seeded = JSON.stringify(draft.value);
}

const edited = () => draft.value !== null && JSON.stringify(draft.value) !== seeded;

watch(settings, (now) => now && !edited() && seed(now), { immediate: true });
// The server labels each bucket per sex (TBL-001); the sex picked here picks the list.
const buckets = computed(() => (settings.value && draft.value ? settings.value.profile.body_fat.choices[draft.value.sex] : []));

// What is typed, read as a number: null for an empty field, NaN for text that is not a
// number (Save waits for it). A decimal comma reads as a point. The server says what is
// out of range.
function typed(text: string, whole: boolean): number | null {
    const plain = text.trim().replace(',', '.');
    if (plain === '') return null;
    return (whole ? /^\d+$/ : /^\d+(\.\d+)?$/).test(plain) ? Number(plain) : NaN;
}

function height(form: Draft): ProfileUpdateInput['height'] {
    return form.unit === 'cm' ? { unit: 'cm', cm: typed(form.cm, false) } : { unit: 'feetInches', feet: typed(form.feet, true), inches: typed(form.inches, false) };
}

const notNumber = computed(() => draft.value !== null && Object.values(height(draft.value)).some((part) => Number.isNaN(part)));

function input(form: Draft): ProfileUpdateInput {
    return {
        name: form.name.trim(),
        sex: form.sex,
        birth_date: form.birth_date,
        height: height(form),
        body_fat_bucket: form.body_fat_bucket,
        uses_glp1: form.uses_glp1,
        experience: form.experience,
    };
}

async function save(): Promise<void> {
    if (!draft.value || notNumber.value) return;
    const outcome = await send('profile_update', { ...input(draft.value) }, 'Your profile could not be saved. Try again.');
    if (outcome === 'done' && settings.value) seed(settings.value);
}

const card = 'divide-y divide-secondary-text/20 overflow-hidden rounded-2xl bg-surface';
const heading = 'px-4 pb-2 text-sm font-semibold text-secondary-text';
const row = 'flex min-h-tap items-center justify-between gap-3 px-4 py-2';
const field = 'rounded-lg bg-background px-3 py-1.5 text-right tabular-nums text-primary-text disabled:opacity-60';
const select = 'max-w-[60%] rounded-lg bg-transparent text-right text-secondary-text disabled:opacity-60';
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <header class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            <span />
            <h1 class="text-center text-lg font-semibold">Profile</h1>
            <button
                v-if="draft"
                type="button"
                data-test="save"
                class="min-h-tap justify-self-end font-semibold text-accent disabled:opacity-40"
                :disabled="busy || notNumber"
                :aria-busy="busy"
                @click="save"
            >
                {{ busy ? 'Saving…' : 'Save' }}
            </button>
        </header>

        <template v-if="settings && draft">
            <p v-if="settings.notice" class="rounded-2xl p-4 text-sm" :class="toneClasses[settings.notice.tone]" data-test="notice">{{ settings.notice.text }}</p>
            <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>

            <div :class="card">
                <label class="flex min-h-tap items-center px-4 py-2">
                    <span class="sr-only">Name</span>
                    <input v-model="draft.name" data-test="name" type="text" maxlength="100" autocomplete="given-name" autocapitalize="words" placeholder="Name" :disabled="busy" class="min-h-tap w-full bg-transparent" />
                </label>
                <div class="px-4 py-2">
                    <div role="group" aria-label="Sex" class="flex rounded-lg bg-background p-0.5">
                        <button
                            v-for="choice in settings.profile.sex.choices"
                            :key="choice.value"
                            type="button"
                            :data-test="`sex-${choice.value}`"
                            :aria-pressed="draft.sex === choice.value"
                            :disabled="busy"
                            class="min-h-tap flex-1 rounded-md text-sm font-semibold"
                            :class="draft.sex === choice.value ? 'bg-surface shadow' : 'text-secondary-text'"
                            @click="draft.sex = choice.value as Sex"
                        >
                            {{ choice.label }}
                        </button>
                    </div>
                </div>
                <label :class="row">
                    <span>Date of birth</span>
                    <input v-model="draft.birth_date" data-test="birth_date" type="date" :disabled="busy" :class="field" />
                </label>
            </div>

            <section aria-labelledby="profile-height">
                <h2 id="profile-height" :class="heading">Height</h2>
                <div :class="card">
                    <div :class="row">
                        <span>Height</span>
                        <span v-if="draft.unit === 'cm'" class="flex items-center gap-2">
                            <input v-model="draft.cm" data-test="height-cm" type="text" inputmode="numeric" aria-label="Height in cm" size="4" :disabled="busy" :class="field" />
                            <span class="text-secondary-text">cm</span>
                        </span>
                        <span v-else class="flex items-center gap-2">
                            <input v-model="draft.feet" data-test="height-feet" type="text" inputmode="numeric" aria-label="Height, feet" size="2" :disabled="busy" :class="field" />
                            <span class="text-secondary-text">ft</span>
                            <input v-model="draft.inches" data-test="height-inches" type="text" inputmode="decimal" aria-label="Height, inches" size="3" :disabled="busy" :class="field" />
                            <span class="text-secondary-text">in</span>
                        </span>
                    </div>
                </div>
                <p v-if="notNumber" class="px-4 pt-2 text-sm text-tone-warning" data-test="not-number">Type your height in numbers, with whole feet.</p>
            </section>

            <section aria-labelledby="profile-body-fat">
                <h2 id="profile-body-fat" :class="heading">Body fat</h2>
                <div :class="card">
                    <label :class="row">
                        <span>Body fat</span>
                        <select v-model="draft.body_fat_bucket" data-test="body_fat" :disabled="busy" :class="select">
                            <option v-for="bucket in buckets" :key="bucket.value" :value="bucket.value">{{ bucket.label }}</option>
                        </select>
                    </label>
                </div>
            </section>

            <div :class="card">
                <SwitchRow
                    label="Taking a GLP-1 medication"
                    data-test="uses_glp1"
                    :checked="draft.uses_glp1"
                    :disabled="busy"
                    @change="draft.uses_glp1 = ($event.target as HTMLInputElement).checked"
                />
                <label :class="row">
                    <span>Training experience</span>
                    <select v-model="draft.experience" data-test="experience" :disabled="busy" :class="select">
                        <option v-for="choice in settings.profile.experience.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                    </select>
                </label>
            </div>
        </template>

        <ReadState v-else :failure="failure" what="Your profile" @retry="load" />
    </main>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { postHost, writeNow } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { useWrite } from '@/app/useWrite';
import type { BillingPortalOpenInput, BillingPortalOpenOutput } from '@/contracts/billing_portal_open';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import type { SettingsUpdateInput } from '@/contracts/settings_update';
import { setAppearance, type Appearance } from '@/design/appearance';
import StatusPill from '@/design/StatusPill.vue';
import { toneClasses } from '@/design/tones';
import DeveloperSection from './DeveloperSection.vue';
import SwitchRow from './SwitchRow.vue';

// The rows of Settings, in rp-clone's sections. Units, display and micro plates save as
// soon as they change, all five together (settings_update); every other row opens its
// own screen. Each control shows what the server holds, so a refused change goes back.
const props = defineProps<{ settings: SettingsShowOutput; load: (options?: { fresh?: boolean }) => Promise<void> }>();

const { send, busy, error } = useWrite(props.load);
const acting = ref(false);
const actionError = ref<string | null>(null);

const held = computed<SettingsUpdateInput>(() => ({
    weight_unit: props.settings.units.weight.value as SettingsUpdateInput['weight_unit'],
    height_unit: props.settings.units.height.value as SettingsUpdateInput['height_unit'],
    appearance: props.settings.appearance.value as SettingsUpdateInput['appearance'],
    consumed_totals: props.settings.consumed_totals.value as SettingsUpdateInput['consumed_totals'],
    micro_plates: props.settings.micro_plates,
}));

// Appearance is also this device's look (SET-002, PWA-041): paint what the server holds,
// whenever it answers, so a choice made on another device or a change with no final
// answer still shows here.
watch(() => held.value.appearance, (appearance) => setAppearance(appearance as Appearance), { immediate: true });

async function change(field: keyof SettingsUpdateInput, event: Event): Promise<void> {
    const control = event.target as HTMLInputElement | HTMLSelectElement;
    const toggle = control instanceof HTMLInputElement && control.type === 'checkbox';
    const value = toggle ? control.checked : control.value;
    actionError.value = null;
    await send('settings_update', { ...held.value, [field]: value }, 'The setting could not be saved. Try again.');
    await nextTick();
    if (toggle) control.checked = held.value.micro_plates;
    else control.value = String(held.value[field]);
}

async function manageSubscription(): Promise<void> {
    await act(async () => {
        const opened = await writeNow<BillingPortalOpenOutput>('billing_portal_open', {} satisfies BillingPortalOpenInput);
        window.location.assign(opened.url);
    }, 'Manage subscription could not be opened. Try again.');
}

// A full page load to sign-in, so nothing of the session stays in memory (ACC-006).
async function signOut(): Promise<void> {
    await act(async () => {
        await postHost('/sign-out', {});
        window.location.assign('/sign-in');
    }, 'You could not be signed out. Try again.');
}

async function act(run: () => Promise<void>, fallback: string): Promise<void> {
    if (acting.value) return;
    acting.value = true;
    actionError.value = null;
    error.value = null;
    try {
        await run();
    } catch (failed) {
        actionError.value = failureMessage(failed, fallback);
    } finally {
        acting.value = false;
    }
}

const card = 'divide-y divide-secondary-text/20 overflow-hidden rounded-2xl bg-surface';
const heading = 'px-4 pb-2 text-sm font-semibold text-secondary-text';
const footer = 'px-4 pt-2 text-sm text-secondary-text';
const row = 'flex min-h-tap items-center justify-between gap-3 px-4 py-2 active:opacity-70';
const select = 'max-w-[60%] rounded-lg bg-transparent text-right text-secondary-text disabled:opacity-60';
</script>

<template>
    <div class="space-y-6 pb-6">
        <p v-if="settings.notice" class="rounded-2xl p-4 text-sm" :class="toneClasses[settings.notice.tone]" data-test="notice">{{ settings.notice.text }}</p>
        <p v-if="error || actionError" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error ?? actionError }}</p>

        <section aria-label="Profile">
            <div :class="card">
                <RouterLink to="/settings/profile" :class="row">
                    <span>Profile</span>
                    <span class="flex min-w-0 items-center gap-2 text-secondary-text"><span class="truncate">{{ settings.profile.name }}</span><span aria-hidden="true">›</span></span>
                </RouterLink>
                <RouterLink to="/settings/eligibility" :class="row">
                    <span class="shrink-0">Re-check eligibility</span>
                    <span class="flex min-w-0 items-center justify-end gap-2"><StatusPill :text="settings.eligibility.text" :tone="settings.eligibility.tone" /><span aria-hidden="true" class="text-secondary-text">›</span></span>
                </RouterLink>
            </div>
        </section>

        <section aria-labelledby="settings-units">
            <h2 id="settings-units" :class="heading">Units</h2>
            <div :class="card">
                <label :class="row">
                    <span>Weight and loads</span>
                    <select :class="select" data-test="weight_unit" :value="settings.units.weight.value" :disabled="busy" @change="change('weight_unit', $event)">
                        <option v-for="choice in settings.units.weight.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                    </select>
                </label>
                <label :class="row">
                    <span>Height</span>
                    <select :class="select" data-test="height_unit" :value="settings.units.height.value" :disabled="busy" @change="change('height_unit', $event)">
                        <option v-for="choice in settings.units.height.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                    </select>
                </label>
            </div>
            <p :class="footer">Everything you have logged converts to the unit you pick.</p>
        </section>

        <section aria-labelledby="settings-display">
            <h2 id="settings-display" :class="heading">Display</h2>
            <div :class="card">
                <label :class="row">
                    <span>Appearance</span>
                    <select :class="select" data-test="appearance" :value="settings.appearance.value" :disabled="busy" @change="change('appearance', $event)">
                        <option v-for="choice in settings.appearance.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                    </select>
                </label>
                <label :class="row">
                    <span>Consumed totals</span>
                    <select :class="select" data-test="consumed_totals" :value="settings.consumed_totals.value" :disabled="busy" @change="change('consumed_totals', $event)">
                        <option v-for="choice in settings.consumed_totals.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                    </select>
                </label>
                <RouterLink to="/settings/diet-filters" :class="row">
                    <span>Diet filters</span>
                    <span class="flex min-w-0 items-center gap-2 text-secondary-text"><span class="truncate">{{ settings.diet_filters.summary }}</span><span aria-hidden="true">›</span></span>
                </RouterLink>
            </div>
            <p :class="footer">Consumed totals can count your planned meals as eaten, or only the meals you checked in.</p>
        </section>

        <section aria-labelledby="settings-training">
            <h2 id="settings-training" :class="heading">Training</h2>
            <div :class="card">
                <SwitchRow label="Micro plates" data-test="micro_plates" :checked="settings.micro_plates" :disabled="busy" @change="change('micro_plates', $event)" />
            </div>
            <p :class="footer">Halves the load step of barbell and smith machine exercises, for example 2.5 kg becomes 1.25 kg.</p>
        </section>

        <section aria-labelledby="settings-schedule">
            <h2 id="settings-schedule" :class="heading">Schedule</h2>
            <div :class="card">
                <RouterLink to="/settings/schedule" :class="row">
                    <span>Schedule defaults</span>
                    <span aria-hidden="true" class="text-secondary-text">›</span>
                </RouterLink>
            </div>
        </section>

        <section aria-labelledby="settings-notifications">
            <h2 id="settings-notifications" :class="heading">Notifications</h2>
            <div :class="card">
                <RouterLink to="/settings/notifications" :class="row">
                    <span>Notifications</span>
                    <span aria-hidden="true" class="text-secondary-text">›</span>
                </RouterLink>
            </div>
            <p :class="footer">Reminders for weigh-ins, meals, workouts, the weekly review and your next mesocycle.</p>
        </section>

        <section aria-labelledby="settings-integrations">
            <h2 id="settings-integrations" :class="heading">Integrations</h2>
            <div :class="card">
                <RouterLink to="/settings/health-bridge" :class="row">
                    <span class="shrink-0">Health bridge</span>
                    <span class="flex min-w-0 items-center justify-end gap-2"><StatusPill :text="settings.health_bridge.text" :tone="settings.health_bridge.tone" /><span aria-hidden="true" class="text-secondary-text">›</span></span>
                </RouterLink>
                <RouterLink to="/settings/tokens" :class="row">
                    <span class="shrink-0">Personal access tokens</span>
                    <span class="flex min-w-0 items-center justify-end gap-2"><StatusPill :text="settings.tokens.text" :tone="settings.tokens.tone" /><span aria-hidden="true" class="text-secondary-text">›</span></span>
                </RouterLink>
                <RouterLink v-if="settings.coach" to="/settings/coach" :class="row">
                    <span class="shrink-0">Coach</span>
                    <span class="flex min-w-0 items-center justify-end gap-2"><StatusPill :text="settings.coach.text" :tone="settings.coach.tone" /><span aria-hidden="true" class="text-secondary-text">›</span></span>
                </RouterLink>
            </div>
        </section>

        <!-- SET-007: there only with billing on (SUB-020). -->
        <section v-if="settings.subscription" aria-labelledby="settings-subscription">
            <h2 id="settings-subscription" :class="heading">Subscription</h2>
            <div :class="card">
                <div :class="row" data-test="subscription">
                    <span class="shrink-0">Plan</span>
                    <StatusPill :text="settings.subscription.text" :tone="settings.subscription.tone" />
                </div>
                <button type="button" :class="[row, 'w-full text-left text-accent disabled:opacity-60']" data-test="manage-subscription" :disabled="acting" @click="manageSubscription">
                    Manage subscription
                </button>
            </div>
        </section>

        <section aria-labelledby="settings-support">
            <h2 id="settings-support" :class="heading">Support</h2>
            <div :class="card">
                <RouterLink to="/learn" :class="row">
                    <span>Help</span>
                    <span aria-hidden="true" class="text-secondary-text">›</span>
                </RouterLink>
                <a :href="`mailto:${settings.support_email}`" :class="row">
                    <span>Contact support</span>
                    <span class="truncate text-secondary-text">{{ settings.support_email }}</span>
                </a>
            </div>
        </section>

        <section aria-labelledby="settings-account">
            <h2 id="settings-account" :class="heading">Account</h2>
            <div :class="card">
                <RouterLink to="/account" :class="row">
                    <span class="shrink-0">Signed in as</span>
                    <span class="flex min-w-0 items-center gap-2 text-secondary-text"><span class="truncate">{{ settings.account_email }}</span><span aria-hidden="true">›</span></span>
                </RouterLink>
                <button type="button" :class="[row, 'w-full text-left disabled:opacity-60']" data-test="sign-out" :disabled="acting" @click="signOut">Sign out</button>
                <RouterLink to="/settings/delete-account" :class="[row, 'text-tone-warning']">Delete account</RouterLink>
            </div>
        </section>

        <!-- SET-011: local and testing only, as the server says. -->
        <section v-if="settings.developer" aria-labelledby="settings-developer">
            <h2 id="settings-developer" :class="heading">Developer</h2>
            <div class="rounded-2xl bg-surface p-4">
                <DeveloperSection />
            </div>
        </section>
    </div>
</template>

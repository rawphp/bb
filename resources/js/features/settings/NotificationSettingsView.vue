<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { NotificationPrefsSetInput } from '@/contracts/notification_prefs_set';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import ToggleSwitch from '@/design/ToggleSwitch.vue';
import { toneClasses } from '@/design/tones';
import HomeScreenHint from '@/features/notifications/HomeScreenHint.vue';
import { usePush } from '@/features/notifications/usePush';

// Settings → Notifications (NOTIF-007): one switch per type, as settings_show lists them.
// The browser is asked for permission only when a switch is turned on (NOTIF-010), never
// when the page opens; looking at this device's push state asks nothing. The server
// decides when each reminder is due.
const props = defineProps<{ payload?: SettingsShowOutput }>();

const { data: settings, failure, load } = useRead<SettingsShowOutput>('settings_show', {}, 'Your notification settings could not be loaded.', props.payload);
const { send, error } = useWrite(load);
const push = usePush();
const pushError = ref<string | null>(null);
// From the tap to the server's answer every switch waits, so the writes go out in the
// order they were tapped and none is dropped.
const toggling = ref(false);

onMounted(() => void push.refresh().catch(() => undefined));

async function toggle(kind: NotificationPrefsSetInput['kind'], event: Event): Promise<void> {
    const box = event.target as HTMLInputElement;
    const on = box.checked;
    toggling.value = true;
    pushError.value = null;
    // The switch is the user's choice and saves either way. Setting up this device starts
    // at the tap, as the permission prompt needs, and runs beside the write: a setup that
    // never settles (no service worker) holds back neither the write nor the other switches.
    if (on) {
        void push.enable().catch(() => {
            pushError.value = 'This device could not be set up for notifications. Turn the switch off and on to try again.';
        });
    }
    try {
        await send('notification_prefs_set', { kind, on } satisfies NotificationPrefsSetInput, 'The notification could not be changed. Try again.');
    } finally {
        toggling.value = false;
        // Show what the server holds, not what was tapped.
        await nextTick();
        box.checked = settings.value?.notifications.find((type) => type.kind === kind)?.on ?? false;
    }
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Notifications</h1>

        <template v-if="settings">
            <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
            <p v-if="pushError" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" data-test="push-error">{{ pushError }}</p>
            <section>
                <div class="divide-y divide-secondary-text/20 overflow-hidden rounded-2xl bg-surface">
                    <ToggleSwitch
                        v-for="type in settings.notifications"
                        :key="type.kind"
                        :label="type.label"
                        :data-test="`notify-${type.kind}`"
                        :model-value="type.on"
                        :disabled="toggling"
                        class="px-4 py-2"
                        @change="toggle(type.kind, $event)"
                    />
                </div>
                <div class="space-y-2 px-4 pt-2 text-sm text-secondary-text">
                    <p>Reminders for weigh-ins, meals, workouts, the weekly review and your next mesocycle, and a note when a change moves meals you have planned. Meal reminders start off.</p>
                    <HomeScreenHint :show="push.needsHomeScreen.value" />
                    <p v-if="push.state.value === 'denied'" data-test="push-blocked">Notifications are blocked for this app. Allow them in your browser's settings to get reminders on this device.</p>
                    <p v-else-if="push.state.value === 'unavailable'" data-test="push-unavailable">This browser can't show notifications from Bodybuilder.</p>
                </div>
            </section>
        </template>

        <ReadState v-else :failure="failure" what="Your notification settings" @retry="load" />
    </main>
</template>

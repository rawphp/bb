<script setup lang="ts">
import { onMounted, type Component } from 'vue';
import { writeNow } from './call';
import { SHEET_HOST_ID, TOAST_HOST_ID } from './hosts';
import { useNavigation } from './navigation';
import TabBar from './TabBar.vue';
import TabStack from './TabStack.vue';
import { TABS } from './tabs';

// The shell (19-views.md, "Shell"): one stack per tab, the tab bar, and above it
// the ActiveWorkoutBar (NAV-004) and the Coach button (NAV-001). Those two fill
// their slots by existing: V_WO1 adds app/ActiveWorkoutBar.vue and V_COACH adds
// app/CoachButton.vue, and neither edits this file. Sheets and toasts teleport
// into the hosts at the end (hosts.ts).
const found = import.meta.glob<Component>(['./ActiveWorkoutBar.vue', './CoachButton.vue'], { eager: true, import: 'default' });
const ActiveWorkoutBar = found['./ActiveWorkoutBar.vue'];
const CoachButton = found['./CoachButton.vue'];

const nav = useNavigation();

// SET-012: the server counts the user's days in the device's time zone, so send it on
// each load. It is never kept in the outbox and a failure shows nothing: the next load
// sends it again, and until then the server keeps the zone it has.
onMounted(() => {
    writeNow('time_zone_set', { time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone }).catch(() => undefined);
});
</script>

<template>
    <div class="bb-shell">
        <div class="bb-stacks">
            <TabStack v-for="tab in TABS" :key="tab.id" :tab="tab.id" :active="nav.state.active === tab.id" />
        </div>
        <div class="bb-above-tabs">
            <div class="bb-floating">
                <div :id="TOAST_HOST_ID" class="bb-toast-host" role="status" aria-live="polite" />
                <div class="bb-coach-slot">
                    <slot name="coach"><component :is="CoachButton" v-if="CoachButton" /></slot>
                </div>
            </div>
            <slot name="active-workout"><component :is="ActiveWorkoutBar" v-if="ActiveWorkoutBar" /></slot>
        </div>
        <TabBar />
        <div :id="SHEET_HOST_ID" class="bb-sheet-host" />
    </div>
</template>

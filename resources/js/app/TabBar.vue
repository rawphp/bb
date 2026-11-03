<script setup lang="ts">
import { useNavigation } from './navigation';
import { TABS, type TabId } from './tabs';

// The bottom bar (NAV-001), above the home indicator (PWA-010). From 768 px wide,
// shell.css lays the same bar out as a left rail (NAV-003).
const nav = useNavigation();

// Outline icons after rp-clone's SF Symbols: sun.max, dumbbell, fork.knife,
// chart.line.uptrend.xyaxis, ellipsis.
const ICONS: Record<TabId, string> = {
    today: 'M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66 1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14-1.41-1.41M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    train: 'M6 7v10M3 9.5v5M18 7v10m3-7.5v5M6 12h12',
    nutrition: 'M7 3v8m-3-8v5a3 3 0 0 0 6 0V3M7 11v10m10-18c-2 0-3 2.5-3 6s1 4 3 4m0-10v18',
    progress: 'M4 4v16h16M7 15l4-4 3 3 6-6m0 0h-4m4 0v4',
    more: 'M6 12h.01M12 12h.01M18 12h.01',
};
</script>

<template>
    <nav class="bb-tab-bar" aria-label="Tabs">
        <button
            v-for="tab in TABS"
            :key="tab.id"
            type="button"
            class="bb-tab"
            :class="{ 'bb-tab--active': nav.state.active === tab.id }"
            :aria-current="nav.state.active === tab.id ? 'page' : undefined"
            :data-tab="tab.id"
            @click="nav.select(tab.id)"
        >
            <svg class="bb-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path :d="ICONS[tab.id]" />
            </svg>
            <span class="bb-tab-label">{{ tab.label }}</span>
        </button>
    </nav>
</template>

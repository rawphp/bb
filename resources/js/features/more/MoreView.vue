<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router';
import NotFound from '@/app/NotFound.vue';
import SyncLine from '@/features/sync/SyncLine.vue';

// The More menu (SET-020): a row for each screen that exists, Activity (CAP-022), then
// the sync line (ACC-003). Ported from rp-clone's MoreView. A row stays out until the
// issue that builds its screen adds its route, as rp-clone's MoreRow.isAvailable did,
// so no row opens Not Found. The script holds the menu's own copy and nothing else.

const ROWS = [
    { title: 'Plan and phases', to: '/plan', icon: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z' },
    { title: 'Adjust phase length', to: '/plan/current/length', icon: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6' },
    { title: 'End current phase', to: '/plan/current/end', icon: 'M4 22V4M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1' },
    { title: 'Weigh-ins', to: '/weigh-ins', icon: 'M9.5 5.5a2.5 2.5 0 1 1 5 0M5.5 8h13l2.5 13H3z' },
    {
        title: 'Custom foods',
        to: '/custom-foods',
        icon: 'M2.3 21.7s9.9-3.5 12.7-6.4a4.5 4.5 0 0 0-6.4-6.3C5.8 11.8 2.3 21.7 2.3 21.7zM8.6 14l-2-2M15.3 15l-2.4-2.5M22 9s-1.3-2-3.5-2C16.9 7 15 9 15 9s1.3 2 3.5 2S22 9 22 9zM15 2s-2 1.3-2 3.5S15 9 15 9s2-1.8 2-3.5C17 3.3 15 2 15 2z',
    },
    { title: 'Custom exercises', to: '/exercises', icon: 'M6.5 6.5v11M17.5 6.5v11M3 9.5v5M21 9.5v5M6.5 12h11' },
    { title: 'Saved meals', to: '/saved-meals', icon: 'M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z' },
    { title: 'Recipes', to: '/nutrition/recipes', icon: 'M3 11h13v1a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5zM16 12h6' },
    { title: 'Shopping list', to: '/shopping', icon: 'M3 3h2l2.4 12.2a1 1 0 0 0 1 .8h9.7a1 1 0 0 0 1-.8L21 7H6M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM18 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2z' },
    {
        title: 'Share progress',
        to: '/progress',
        icon: 'M12 3v12M8 7l4-4 4 4M8 10H6.5A1.5 1.5 0 0 0 5 11.5v8A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5v-8A1.5 1.5 0 0 0 17.5 10H16',
    },
    { title: 'Learn', to: '/learn', icon: 'M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2zM22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7z' },
    {
        title: 'Settings',
        to: '/settings',
        icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z',
    },
    { title: 'Activity', to: '/activity', icon: 'M3 12a9 9 0 1 0 2.6-6.4L3 8M3 3v5h5M12 7v5l3.5 2' },
] as const;

const router = useRouter();

// Whether the app has a screen at this path. Production drops the dev routes, so the gallery goes too.
function built(path: string): boolean {
    const matched = router.resolve(path).matched;
    return matched.length > 0 && matched.every((record) => record.components?.default !== NotFound);
}

const rows = ROWS.filter((row) => built(row.to));
const gallery = built('/dev/gallery');
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="mb-4 px-1 text-3xl font-bold">More</h1>

        <ul class="overflow-hidden rounded-2xl bg-surface">
            <li v-for="row in rows" :key="row.to">
                <RouterLink :to="row.to" class="flex min-h-tap items-center gap-4 pl-4 active:opacity-70" data-test="more-row">
                    <svg viewBox="0 0 24 24" class="size-6 shrink-0 text-accent" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path :d="row.icon" />
                    </svg>
                    <span class="flex min-h-tap flex-1 items-center gap-3 border-secondary-text/15 py-3 pr-4 [li+li_&]:border-t">
                        <span class="flex-1">{{ row.title }}</span>
                        <svg viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                            <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                </RouterLink>
            </li>
        </ul>

        <!-- rp-clone's Debug section: local and testing only, where the dev routes are on. -->
        <section v-if="gallery" class="mt-6" data-test="developer">
            <h2 class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">Developer</h2>
            <RouterLink to="/dev/gallery" class="flex min-h-tap items-center gap-3 rounded-2xl bg-surface px-4 py-3 active:opacity-70">
                <span class="flex-1">Gallery</span>
                <svg viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                    <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </RouterLink>
        </section>

        <div class="mt-6">
            <SyncLine />
        </div>
    </main>
</template>

<script setup lang="ts">
import { dismissInstall, installSheet } from './install';
import { applyUpdate, updateBanner } from './updates';

// The update banner (PWA-035) and the iPhone install sheet (PWA-003), drawn over
// whatever screen is open. main.ts mounts this on its own root.
</script>

<template>
    <button
        v-if="updateBanner"
        type="button"
        class="fixed left-1/2 z-50 min-h-tap -translate-x-1/2 rounded-full bg-surface px-5 text-sm font-medium text-primary-text shadow-lg"
        style="top: calc(env(safe-area-inset-top) + 8px)"
        @click="applyUpdate"
    >
        Updated, tap to reload
    </button>

    <div v-if="installSheet" class="fixed inset-0 z-50 flex items-end bg-black/40" @click.self="dismissInstall">
        <section
            role="dialog"
            aria-modal="true"
            aria-labelledby="install-title"
            class="mx-auto w-full max-w-md rounded-t-2xl bg-surface p-6 text-primary-text"
            style="padding-bottom: calc(env(safe-area-inset-bottom) + 24px)"
        >
            <h2 id="install-title" class="text-lg font-semibold">Add Bodybuilder to your Home Screen</h2>
            <p class="mt-2 text-secondary-text">
                It opens full screen and works offline. Tap
                <svg class="inline size-5 align-text-bottom text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Share">
                    <path d="M12 3v12M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1" />
                </svg>
                Share, then <strong class="font-semibold text-primary-text">Add to Home Screen</strong>.
            </p>
            <button type="button" class="mt-6 min-h-tap w-full rounded-xl bg-accent font-medium text-on-accent" @click="dismissInstall">Not now</button>
        </section>
    </div>
</template>

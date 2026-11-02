<script setup lang="ts">
import BottomSheet from '../design/BottomSheet.vue';
import { dismissInstall, installSheet } from './install';
import { applyUpdate, updateBanner } from './updates';

// The update banner (PWA-035) and the iPhone install sheet (PWA-003), drawn over
// whatever screen is open. main.ts mounts this on its own root.
</script>

<template>
    <button
        v-if="updateBanner"
        type="button"
        class="fixed top-[calc(env(safe-area-inset-top)_+_0.5rem)] left-1/2 z-50 min-h-tap -translate-x-1/2 rounded-full bg-surface px-5 text-sm font-medium text-primary-text shadow-lg"
        @click="applyUpdate"
    >
        Updated, tap to reload
    </button>

    <BottomSheet :open="installSheet" title="Add to Home Screen" :save-title="null" cancel-title="Not now" :heights="['half']" @cancel="dismissInstall">
        <div class="p-4">
            <p class="font-semibold">Add Bodybuilder to your Home Screen</p>
            <p class="mt-2 text-secondary-text">
                It opens full screen and works offline. Tap
                <svg class="inline size-5 align-text-bottom text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Share">
                    <path d="M12 3v12M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1" />
                </svg>
                Share, then <strong class="font-semibold text-primary-text">Add to Home Screen</strong>.
            </p>
        </div>
    </BottomSheet>
</template>

import { computed, shallowReactive } from 'vue';

// Open BottomSheets, oldest first. Only the top one answers Escape and Tab, so a sheet
// opened over another closes first. The app reads the count: it keeps its update banner
// away while any sheet is open (PWA-035).
export const openSheets = shallowReactive<symbol[]>([]);

export const anySheetOpen = computed(() => openSheets.length > 0);

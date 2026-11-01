<script setup lang="ts">
import { computed } from 'vue';
import { clearWalk, isWalked, now, walkDays, walkToDate } from '@/app/clock';

// Settings → Developer (SET-011): the time-walk clock. Local and testing only;
// the settings screen (V_SETTINGS2) mounts it there.

// now() and isWalked() read the reactive offset, so these follow the walk.
const walked = computed(isWalked);
const date = computed(() => pickerValue(now()));
// The clock walks forward only, so the picker starts at the device's today.
const min = pickerValue(new Date());
const label = computed(() => now().toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'short' }));

function pickerValue(day: Date): string {
    return [day.getFullYear(), day.getMonth() + 1, day.getDate()].map((part) => String(part).padStart(2, '0')).join('-');
}

function pick(event: Event): void {
    const input = event.target as HTMLInputElement;
    walkToDate(input.value);
    // A refused past date leaves the offset unchanged, so put the shown date back.
    input.value = date.value;
}
</script>

<template>
    <section class="space-y-3" aria-labelledby="developer-clock">
        <h2 id="developer-clock" class="font-semibold">Clock</h2>
        <p class="text-sm">
            <span class="opacity-70">{{ walked ? 'Walked to' : 'Device time' }}:</span>
            {{ label }}
        </p>
        <label class="block text-sm">
            <span class="opacity-70">Date</span>
            <input type="date" class="mt-1 block w-full rounded border px-3 py-2" :value="date" :min="min" @change="pick" />
        </label>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="rounded border px-3 py-2" data-test="plus-day" @click="walkDays(1)">+1 day</button>
            <button type="button" class="rounded border px-3 py-2" data-test="plus-week" @click="walkDays(7)">+7 days</button>
            <button v-if="walked" type="button" class="rounded border px-3 py-2" data-test="clear" @click="clearWalk()">Clear</button>
        </div>
    </section>
</template>

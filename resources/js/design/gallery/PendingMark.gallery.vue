<script setup lang="ts">
import PendingMark from '../PendingMark.vue';
import Skeleton from '../Skeleton.vue';

// PendingMark (OFF-008, OFF-009) in each VIEW-004 state.
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <p class="text-base text-primary-text">Set 3 · <PendingMark>100 kg × 8</PendingMark></p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <PendingMark />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <p class="mb-2 text-sm text-secondary-text">A value still loading has nothing pending, so it shows a skeleton and no mark.</p>
            <Skeleton shape="bar" label="Loading set 3" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <p class="text-base text-primary-text"><PendingMark>82.4 kg</PendingMark></p>
            <p role="alert" class="mt-2 text-sm text-tone-warning">Couldn't sync. That date is more than 7 days ago.</p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <p class="text-base text-primary-text">Calories · <PendingMark derived>1,840 left</PendingMark></p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <p class="text-base text-primary-text opacity-60">Breakfast · <PendingMark>Checked in</PendingMark></p>
        </div>
    </div>
</template>

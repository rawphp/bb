<script setup lang="ts">
import Skeleton from '../Skeleton.vue';

// Skeleton (PWA-024) in each shape, then each VIEW-004 state.
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <div class="flex flex-col gap-4">
                <Skeleton shape="row" :count="3" label="Loading exercises" />
                <Skeleton shape="card" label="Loading meal" />
                <Skeleton shape="bar" label="Loading progress" />
            </div>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <p class="mb-2 text-sm text-secondary-text">A count of zero draws nothing.</p>
            <Skeleton shape="row" :count="0" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <Skeleton shape="row" :count="2" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <p class="mb-2 text-sm text-secondary-text">A failed read replaces the skeleton with its message.</p>
            <p role="alert" class="text-sm text-tone-warning">Couldn't load your meals.</p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <p class="mb-2 text-sm text-secondary-text">Pending values show from cache with a pending mark, never as a skeleton.</p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <p class="mb-2 text-sm text-secondary-text">A skeleton has no controls, so it has no disabled look.</p>
        </div>
    </div>
</template>

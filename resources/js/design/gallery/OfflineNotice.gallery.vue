<script setup lang="ts">
import OfflineNotice from '../OfflineNotice.vue';
import Skeleton from '../Skeleton.vue';

// OfflineNotice (OFF-003, OFF-031) in each VIEW-004 state.
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <OfflineNotice needs="Food search needs a connection." />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <OfflineNotice />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <p class="mb-2 text-sm text-secondary-text">While a read is in flight the screen shows a skeleton; the notice shows only once it fails offline.</p>
            <Skeleton shape="card" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <OfflineNotice needs="The weekly review needs a connection. Open it once online to see this week." />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <p class="mb-2 text-sm text-secondary-text">Pending writes don't change the notice; they wait in the outbox.</p>
            <OfflineNotice needs="Recipe import needs a connection." />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <div class="flex flex-col gap-2">
                <input
                    type="search"
                    disabled
                    aria-label="Search foods"
                    placeholder="Search foods"
                    class="min-h-tap w-full rounded-xl border border-secondary-text/25 bg-surface px-3.5 text-base opacity-60"
                />
                <OfflineNotice needs="Food search needs a connection." />
            </div>
        </div>
    </div>
</template>

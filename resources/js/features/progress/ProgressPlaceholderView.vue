<script setup lang="ts">
import BottomSheet from '@/design/BottomSheet.vue';

// A sheet for something a later issue builds.
defineProps<{ open: boolean; title: string; message: string }>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <BottomSheet :open="open" :title="title" :save-title="null" cancel-title="Close" :heights="['half']" @cancel="emit('close')">
        <p class="p-6 text-center text-secondary-text">{{ message }}</p>
    </BottomSheet>
</template>

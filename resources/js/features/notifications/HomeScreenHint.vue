<script setup lang="ts">
// NOTIF-010: on iPhone, Web Push needs the app on the home screen. Settings
// shows this beside the notification switches when usePush() says so.
defineProps<{ show: boolean }>();
</script>

<template>
    <p v-if="show" class="text-sm opacity-70">
        To get notifications on iPhone, add Bodybuilder to your Home Screen: tap Share, then Add to Home Screen, and open it from there.
    </p>
</template>

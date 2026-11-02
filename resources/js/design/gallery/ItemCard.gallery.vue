<script setup lang="ts">
import ItemCard from '../ItemCard.vue';

// Each kind of card, then the VIEW-004 states on a meal card.
type Entry = { name: string; props: InstanceType<typeof ItemCard>['$props']; body?: string };

const meal = { title: 'Breakfast', time: '07:30', status: { kind: 'checkbox', isOn: false, label: 'Breakfast eaten' } } as const;
const mealBody = 'Oats 80 g · Whey 30 g';

const entries: Entry[] = [
    { name: 'meal', props: meal, body: mealBody },
    {
        name: 'meal check-in',
        props: {
            title: 'Lunch',
            time: '12:30',
            status: { kind: 'check-in', state: 'under', label: 'Checked in, under target', mealName: 'Lunch' },
        },
        body: 'Eaten: 500 kcal, 40 g protein',
    },
    { name: 'workout', props: { title: 'Upper A', time: '18:00', status: { kind: 'button', title: 'Start' } }, body: 'Bench press · 3 sets' },
    { name: 'weigh-in', props: { title: 'Weigh-in', time: '07:00', status: { kind: 'value', text: '82.4 kg' } } },
    { name: 'busy period', props: { title: 'Work', time: '09:00–17:00' } },
    { name: 'empty', props: meal },
    { name: 'loading', props: { ...meal, state: 'loading' }, body: mealBody },
    { name: 'error', props: { ...meal, state: 'error' }, body: mealBody },
    { name: 'pending', props: { ...meal, state: 'pending' }, body: mealBody },
    { name: 'disabled', props: { ...meal, disabled: true }, body: mealBody },
];
</script>

<template>
    <div class="flex flex-col gap-4">
        <figure v-for="entry in entries" :key="entry.name" class="flex flex-col gap-1">
            <figcaption class="text-sm text-secondary-text">{{ entry.name }}</figcaption>
            <ItemCard v-bind="entry.props">
                <template #icon>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75">
                        <circle cx="12" cy="12" r="8.5" />
                        <path d="M12 7.5V12l3 2" stroke-linecap="round" />
                    </svg>
                </template>
                <template v-if="entry.body" #default>
                    <p class="text-secondary-text">{{ entry.body }}</p>
                </template>
            </ItemCard>
        </figure>
    </div>
</template>

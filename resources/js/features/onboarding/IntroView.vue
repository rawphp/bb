<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';

// The three intro cards before sign-up (ONB-001). Swipe or Next walks them; the last
// card's Get started leads to sign-up. Static copy: it shows before there is an account
// to read anything for. Ported from rp-clone's IntroView and OnboardingIntro.
const cards = [
    {
        title: 'Meals planned first',
        body: 'Your plan sets calories and macros for each day. Pick your foods and the app fits them to your targets.',
        // fork.knife
        icon: 'M4 2h1.5v6h1.25V2h1.5v6H9.5V2H11v6.5a2.75 2.75 0 0 1-2 2.65V21a1.25 1.25 0 0 1-2.5 0v-9.85A2.75 2.75 0 0 1 4 8.5zm14 0c-2 1-3.5 3.5-3.5 7.5v3.5h2V21a1.25 1.25 0 0 0 2.5 0V3a1 1 0 0 0-1-1z',
    },
    {
        title: 'Training that adjusts to you',
        body: 'After each session, tell the app how it felt. Your sets and loads change to match your feedback.',
        // dumbbell
        icon: 'M5 7h2v10H5zm-3 3h2v4H2zm6-4h2v12H8zm6 0h2v12h-2zm3 1h2v10h-2zm3 3h2v4h-2zM10 11h4v2h-4z',
    },
    {
        title: 'One review each week',
        body: 'Once a week the app reads your weigh-ins and training, then adjusts your calories and plan. One decision a week.',
        // calendar
        icon: 'M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2zM5 9v11h14V9zm2 2h3v3H7z',
    },
] as const;

const router = useRouter();
const page = ref(0);
const isLast = computed(() => page.value === cards.length - 1);

function next(): void {
    if (isLast.value) void router.push('/sign-in');
    else page.value += 1;
}

// A horizontal swipe of more than 50 px turns the page, as the paged TabView does.
let swipeFrom: number | null = null;

function swiped(event: PointerEvent): void {
    if (swipeFrom === null) return;
    const distance = event.clientX - swipeFrom;
    swipeFrom = null;
    if (distance < -50 && !isLast.value) page.value += 1;
    if (distance > 50 && page.value > 0) page.value -= 1;
}
</script>

<template>
    <main class="mx-auto flex min-h-dvh w-full max-w-[560px] flex-col gap-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-[env(safe-area-inset-top)]">
        <div class="flex flex-1 overflow-hidden" @pointerdown="swipeFrom = $event.clientX" @pointerup="swiped" @pointercancel="swipeFrom = null">
            <div class="flex w-full shrink-0 transition-transform duration-300 motion-reduce:transition-none" :style="{ transform: `translateX(-${page * 100}%)` }">
                <section
                    v-for="(card, index) in cards"
                    :key="card.title"
                    data-test="card"
                    :aria-hidden="index === page ? undefined : 'true'"
                    :inert="index !== page"
                    class="flex w-full shrink-0 flex-col items-center justify-center gap-5 px-8 pb-10 text-center"
                >
                    <svg viewBox="0 0 24 24" class="size-16 text-accent" fill="currentColor" aria-hidden="true">
                        <path :d="card.icon" fill-rule="evenodd" />
                    </svg>
                    <h2 class="text-3xl font-bold text-primary-text">{{ card.title }}</h2>
                    <p class="text-secondary-text">{{ card.body }}</p>
                </section>
            </div>
        </div>

        <div class="flex justify-center gap-1">
            <button
                v-for="(card, index) in cards"
                :key="card.title"
                type="button"
                data-test="dot"
                :aria-label="`Card ${index + 1} of ${cards.length}`"
                :aria-current="index === page ? 'true' : undefined"
                class="flex size-6 items-center justify-center"
                @click="page = index"
            >
                <span class="size-2 rounded-full" :class="index === page ? 'bg-accent' : 'bg-secondary-text/30'" />
            </button>
        </div>

        <div class="px-6">
            <button
                type="button"
                data-test="intro-next"
                class="min-h-tap w-full rounded-xl bg-accent font-semibold text-on-accent active:opacity-70"
                @click="next"
            >
                {{ isLast ? 'Get started' : 'Next' }}
            </button>
        </div>
    </main>
</template>
